#!/usr/bin/env python3
"""Run on VPS5. Targeted HTTP hold, reversible file deploy, no accounting writes."""
import argparse,hashlib,json,shutil,subprocess
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('mode',choices=['backup','pause','apply','resume','rollback','check']);p.add_argument('environment',choices=['uat','prod']);p.add_argument('release');a=p.parse_args()
release=Path(a.release);stage=release/'stage';out=release/a.environment;out.mkdir(parents=True,exist_ok=True)
domain=('zuat-' if a.environment=='uat' else '')+'accounting-back.myt-emr.com'
root=Path('/home/mytemr/web')/domain/'public_html';manifest=json.loads((stage/'manifest.json').read_text())
if a.mode=='backup':
 states={}
 for file in list(manifest)+['.htaccess','app/Models/Direct_payment_entry.php']:
  dest=out/'files'/file;dest.parent.mkdir(parents=True,exist_ok=True)
  src=root/file;states[file]={'exists':src.exists()}
  if src.exists():shutil.copy2(src,dest);states[file]['sha256']=hashlib.sha256(src.read_bytes()).hexdigest()
 (out/'original.json').write_text(json.dumps(states,indent=2)+'\n');print(a.environment+' API files backed up.',flush=True)
elif a.mode=='pause':
 original=(out/'files/.htaccess').read_text()
 hold='''# Temporary APV release hold\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{REQUEST_URI} ^/(index\\.php/)?(accounts_payable_vouchers|direct_payments)(/|$) [OR]\nRewriteCond %{REQUEST_URI} ^/(index\\.php/)?cron/post_direct_payment_journal_entries(/|$)\nRewriteRule ^ - [R=503,L]\n</IfModule>\n'''
 (root/'.htaccess').write_text(hold+original)
 for route in ['accounts_payable_vouchers/get','direct_payments/get','cron/post_direct_payment_journal_entries']:
  code=subprocess.check_output(['curl','--max-time','20','-sS','-o','/dev/null','-w','%{http_code}','https://'+domain+'/'+route],text=True)
  if code!='503':
   shutil.copy2(out/'files/.htaccess',root/'.htaccess');raise RuntimeError('HTTP hold failed: '+route+' '+code)
 print(a.environment+' APV/direct-payment endpoints and amortization route held (503).',flush=True)
elif a.mode=='apply':
 try:
  for file,expected in manifest.items():
   src=stage/file;assert hashlib.sha256(src.read_bytes()).hexdigest()==expected
   dst=root/file;dst.parent.mkdir(parents=True,exist_ok=True);tmp=dst.with_name(dst.name+'.release_tmp')
   shutil.copy2(src,tmp);shutil.chown(tmp,user='mytemr',group='mytemr');tmp.chmod(0o644);tmp.replace(dst)
   subprocess.run(['/usr/bin/php','-l',str(dst)],check=True,capture_output=True)
   assert hashlib.sha256(dst.read_bytes()).hexdigest()==expected
  (out/'deployed.json').write_text(json.dumps(manifest,indent=2)+'\n')
  print(a.environment+' nine PHP files deployed; syntax and checksums verified.',flush=True)
 except Exception:
  states=json.loads((out/'original.json').read_text())
  for file in manifest:
   if states[file]['exists']:shutil.copy2(out/'files'/file,root/file)
   else:(root/file).unlink(missing_ok=True)
  raise
elif a.mode=='resume':
 shutil.copy2(out/'files/.htaccess',root/'.htaccess');print(a.environment+' original routing restored; schedules unchanged.',flush=True)
elif a.mode=='rollback':
 states=json.loads((out/'original.json').read_text())
 for file in manifest:
  if states[file]['exists']:shutil.copy2(out/'files'/file,root/file)
  else:(root/file).unlink(missing_ok=True)
 print(a.environment+' original PHP files restored; hold retained until DB recovery verified.',flush=True)
elif a.mode=='check':
 for file,expected in manifest.items():
  assert hashlib.sha256((root/file).read_bytes()).hexdigest()==expected
  subprocess.run(['/usr/bin/php','-l',str(root/file)],check=True,capture_output=True)
 assert hashlib.sha256((root/'.htaccess').read_bytes()).hexdigest()==hashlib.sha256((out/'files/.htaccess').read_bytes()).hexdigest()
 results={}
 for route in ['accounts_payable_vouchers/get?cost_center_id=2&page=1&data_size=1','direct_payments/get?cost_center_id=2&page=1&data_size=1']:
  code=subprocess.check_output(['curl','--max-time','20','-sS','-o','/dev/null','-w','%{http_code}','https://'+domain+'/'+route],text=True)
  if code not in ['200','400','401','403','404']:raise RuntimeError('Unexpected read-only endpoint HTTP '+code)
  results[route]=code
 (out/'http_checks.json').write_text(json.dumps(results,indent=2)+'\n');print(a.environment+' syntax/checksums/routing verified; unauthenticated GET statuses '+json.dumps(results),flush=True)
