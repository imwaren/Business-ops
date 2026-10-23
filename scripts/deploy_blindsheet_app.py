#!/usr/bin/env python3
"""Private VPS5 blindsheet file deployment, temporary HTTP hold, read-only checks."""
import argparse,hashlib,json,os,shutil,subprocess,time
from pathlib import Path
os.umask(0o077)
p=argparse.ArgumentParser(description=__doc__);p.add_argument('mode',choices=['inspect','backup','privileges','pause','apply','verify-held','verify','rollback','resume','http']);p.add_argument('environment',choices=['uat','prod']);p.add_argument('release',type=Path);a=p.parse_args()
release=a.release;out=release/a.environment;out.mkdir(parents=True,exist_ok=True)
domain=('zuat-' if a.environment=='uat' else '')+'accounting-back.myt-emr.com';root=Path('/home/mytemr/web')/domain/'public_html';manifest=json.loads((release/'manifest.json').read_text());files=['app/Models/Branch_item_blindsheet.php','app/Controllers/Items.php']
def digest(path):return hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else None
def save(name,value):(out/name).write_text(json.dumps(value,indent=2)+'\n')
def lint(path):
 for php in ['/usr/bin/php8.2','/usr/bin/php8.3']:
  if Path(php).exists():subprocess.run([php,'-l',str(path)],check=True,capture_output=True)
def protected():
 paths=[root/name for name in ['.env','spark','app/Config/Database.php','app/Config/Constants.php','app/Common.php','app/Core/MYT_Model.php','app/Controllers/Traits/Weighted_cost.php','app/Controllers/Traits/Inventory_snapshot.php']]
 paths+=list(Path('/etc/cron.d').glob('*'))
 return {str(path):digest(path) for path in paths if path.is_file()}
def replace(source,target,metadata):
 temp=target.with_name('.'+target.name+'.blindsheet_release');shutil.copyfile(source,temp);os.chown(temp,metadata['uid'],metadata['gid']);temp.chmod(metadata['mode']);temp.replace(target)
def inspect():
 if digest(root/'app/Controllers/Items.php')!=manifest['expected_items_before']:raise RuntimeError('Items changed since inspected baseline')
 if (root/'app/Models/Branch_item_blindsheet.php').exists():raise RuntimeError('Unexpected existing blindsheet model')
 for name in files:
  if digest(release/'stage'/name)!=manifest['files'][name]:raise RuntimeError('Release checksum mismatch')
  lint(release/'stage'/name)
 print(a.environment+' pre-feature controller and release syntax/checksums verified.',flush=True)
if a.mode=='inspect':inspect()
elif a.mode=='privileges':
 result=subprocess.run(['/usr/bin/php8.2',str(release/'probe.php'),str(root),str(release/'stage'),'--privileges-only'],text=True,capture_output=True,timeout=150)
 (out/'privileges.stdout.private.log').write_text(result.stdout);(out/'privileges.stderr.private.log').write_text(result.stderr)
 data=json.loads(result.stdout)
 if result.returncode or not data.get('ok'):raise RuntimeError('Effective privilege precheck failed')
 save('privileges.json',data);print(a.environment+' effective inventory privileges verified before the database write hold; no grants or records changed.',flush=True)
elif a.mode=='backup':
 inspect();original={}
 for name in files+['.htaccess']:
  path=root/name;exists=path.exists();meta=(path if exists else root/'app/Controllers/Items.php').stat()
  original[name]={'exists':exists,'sha256':digest(path),'uid':meta.st_uid,'gid':meta.st_gid,'mode':meta.st_mode&0o777 if exists else 0o644}
  if exists:
   target=out/'before'/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(path,target);target.chmod(0o600)
 save('original.json',original);save('protected.before.json',protected());save('logs.before.json',{str(path):path.stat().st_size for path in (root/'writable/logs').glob('*.log')})
 (out/'RECOVERY.txt').write_text('Keep maintenance hold. Restore original Items controller first, then remove the newly installed model. Restore exact DB definitions and verify history on VPS6. Only after recovery passes, restore original .htaccess and cron service state.\n')
 print(a.environment+' exact files/ownership/modes and unrelated file/schedule fingerprints backed up.',flush=True)
elif a.mode=='pause':
 original=json.loads((out/'original.json').read_text());cron=subprocess.run(['systemctl','is-active','cron'],capture_output=True,text=True).stdout.strip()
 if not (out/'maintenance.json').exists():save('maintenance.json',{'cron':cron})
 subprocess.run(['systemctl','stop','cron'],check=True)
 hold=b'# Temporary authorized blindsheet rollout hold\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n</IfModule>\n'
 path=out/'htaccess.hold';path.write_bytes(hold+(out/'before/.htaccess').read_bytes());replace(path,root/'.htaccess',original['.htaccess'])
 code=subprocess.check_output(['curl','--max-time','25','-sS','-o','/dev/null','-w','%{http_code}','https://'+domain+'/items/get_blindsheet'],text=True)
 if code!='503':raise RuntimeError('Accounting HTTP maintenance hold not effective')
 print(a.environment+' HTTP maintenance hold verified; application scheduler paused.',flush=True)
elif a.mode=='apply':
 original=json.loads((out/'original.json').read_text())
 if digest(root/'app/Controllers/Items.php')!=manifest['expected_items_before']:raise RuntimeError('Controller drift before apply')
 for name in files:
  src=release/'stage'/name;dst=root/name;lint(src);replace(src,dst,original[name]);lint(dst)
  if digest(dst)!=manifest['files'][name]:raise RuntimeError('Deployed checksum mismatch')
 save('installed.json',manifest['files']);print(a.environment+' model then controller installed atomically; PHP syntax/checksums passed.',flush=True)
elif a.mode in ['verify-held','verify']:
 for name in files:
  lint(root/name)
  if digest(root/name)!=manifest['files'][name]:raise RuntimeError('Installed checksum mismatch')
 # Graceful FPM reload ensures no stale opcode uses the pre-feature controller.
 for service in ['php8.2-fpm','php8.3-fpm']:
  if subprocess.run(['systemctl','is-active','--quiet',service]).returncode==0:subprocess.run(['systemctl','reload',service],check=True)
 time.sleep(3)
 command=['/usr/bin/php8.2',str(release/'probe.php'),str(root)]+(['--held'] if a.mode=='verify-held' else [])
 result=subprocess.run(command,text=True,capture_output=True,timeout=150)
 (out/'probe.stdout.private.log').write_text(result.stdout);(out/'probe.stderr.private.log').write_text(result.stderr)
 try:data=json.loads(result.stdout)
 except ValueError:raise RuntimeError('Probe did not return structured JSON; inspect private logs')
 if result.returncode or not data.get('ok'):raise RuntimeError('Application readiness failed; inspect private probe logs')
 if protected()!=json.loads((out/'protected.before.json').read_text()):raise RuntimeError('Unrelated application/configuration/schedule file changed')
 save('verified.held.json' if a.mode=='verify-held' else 'verified.json',data)
 print(a.environment+' application credentials readiness and '+str(data['checks'])+' read-only checks passed; unrelated files/schedules unchanged.',flush=True)
elif a.mode=='rollback':
 original=json.loads((out/'original.json').read_text())
 for name in reversed(files):
  dst=root/name
  if original[name]['exists']:replace(out/'before'/name,dst,original[name]);lint(dst)
  else:dst.unlink(missing_ok=True)
 for name in files:
  if digest(root/name)!=original[name]['sha256']:raise RuntimeError('Rollback checksum mismatch')
 for service in ['php8.2-fpm','php8.3-fpm']:
  if subprocess.run(['systemctl','is-active','--quiet',service]).returncode==0:subprocess.run(['systemctl','reload',service],check=True)
 save('recovered.json',{'files':'restored','new_model':'removed','hold':'retained'});print(a.environment+' previous controller restored before removing model; HTTP hold retained.',flush=True)
elif a.mode=='resume':
 if not (out/'verified.json').exists() and not (out/'recovered.json').exists():raise RuntimeError('No successful verification/recovery; retain HTTP hold')
 original=json.loads((out/'original.json').read_text());replace(out/'before/.htaccess',root/'.htaccess',original['.htaccess'])
 if digest(root/'.htaccess')!=original['.htaccess']['sha256']:raise RuntimeError('Original routing differs')
 if json.loads((out/'maintenance.json').read_text())['cron']=='active':subprocess.run(['systemctl','start','cron'],check=True)
 print(a.environment+' original routing and scheduler state restored.',flush=True)
elif a.mode=='http':
 results={}
 for route in ['items/get_blindsheet?branch_id=2','items/get?branch_id=2&origin=csr']:
  codes=[]
  for attempt in range(3):
   output=subprocess.check_output(['curl','--max-time','30','-sS','-H','Cache-Control: no-cache','-w','\n%{http_code}','https://'+domain+'/'+route],text=True)
   body,code=output.rsplit('\n',1);codes.append(code)
   (out/('http_'+str(len(results))+'.body.private')).write_text(body)
   save('http.attempts.json',{**results,route:codes})
   if code=='401':break
   if code not in ['502','503','504']:break
   time.sleep(2)
  if code!='401':raise RuntimeError('Unauthenticated route did not reject with HTTP 401')
  results[route]=int(code)
 errors={'new_error_lines':0,'new_blindsheet_error_lines':0}
 for path in (root/'writable/logs').glob('*.log'):
  offset=json.loads((out/'logs.before.json').read_text()).get(str(path),0)
  with path.open('rb') as handle:handle.seek(offset);lines=handle.read().decode('utf8',errors='replace').splitlines()
  for line in lines:
   if 'ERROR -' in line or 'CRITICAL -' in line:
    errors['new_error_lines']+=1
    if 'blindsheet' in line.lower() or 'Items.php' in line:errors['new_blindsheet_error_lines']+=1
 if errors['new_blindsheet_error_lines']:raise RuntimeError('New blindsheet application errors; inspect private logs')
 if protected()!=json.loads((out/'protected.before.json').read_text()):raise RuntimeError('Unrelated files changed')
 save('http.json',{'unauthenticated_gets':results,'authenticated_http':'not checked; no valid current credentials supplied','logs':errors})
 print(a.environment+' HTTP authentication gates verified; no new blindsheet errors; authenticated HTTP limitation recorded.',flush=True)
