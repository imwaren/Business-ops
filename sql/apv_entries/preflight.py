#!/usr/bin/env python3
"""Read-only migration gate. Exit 2 for any unresolved historical exception."""
import argparse, csv, json, subprocess
from collections import Counter
from decimal import Decimal as D
from pathlib import Path

BASES = ['mytemr_diagnostic_mobile','mytemr_diagnostic','mytemr_diagnostic_mactan','mytemr_diagnostic_conso','mytemr_diagnostic_mantlewood']
p=argparse.ArgumentParser();p.add_argument('--environment',choices=['uat','prod','both'],default='both');p.add_argument('--output',default='sql/apv_entries/audit');args=p.parse_args()
out=Path(args.output);out.mkdir(parents=True,exist_ok=True)
ssh=['ssh','-p','1969','-i',str(Path.home()/'.ssh/id_ed25519'),'root@vps6.znvme.mytdedi01.filipinowebservices.com','mariadb -N -B']

def query(sql):
 r=subprocess.run(ssh,input=sql,text=True,capture_output=True)
 if r.returncode: raise RuntimeError(r.stderr.strip())
 return [row.split('\t') for row in r.stdout.splitlines()]

exceptions=[];candidates=[];stats={};dependencies=[];legacy_entries=[]
for env in (['uat','prod'] if args.environment=='both' else [args.environment]):
 master='mytemr_master'+('_uat' if env=='uat' else '')
 for base in BASES:
  db=base+('_uat' if env=='uat' else '')
  columns=query(f"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{db}' AND TABLE_NAME IN ('accounts_payable_voucher','accounts_payable_voucher_entry','direct_payment','direct_payment_entry','accounts_payable_voucher_amortization_entry');")
  (out/(db+'_columns.tsv')).write_text('\n'.join('\t'.join(x) for x in columns)+'\n')
  tables={x[0] for x in columns}
  required={'accounts_payable_voucher','accounts_payable_voucher_entry','direct_payment'}
  if not required.issubset(tables):
   exceptions.append([db,'schema','', 'Missing required tables: '+','.join(required-tables)]);continue
  for row in columns:
   if row[0]=='accounts_payable_voucher_entry' and row[1] in ('debit','credit') and row[2]!='decimal(13,4)':
    exceptions.append([db,'schema','', 'Unexpected entry amount precision'])
  headers=query(f"SELECT id,type,type_id,amount,status FROM {db}.accounts_payable_voucher WHERE is_deleted=0;")
  for id,kind,origin,amount,status in headers:
   existing=query(f"SELECT e.account_type_id,e.debit,e.credit,COALESCE(a.code,''),IF(a.id IS NOT NULL AND a.is_deleted=0 AND a.status='active',1,0) FROM {db}.accounts_payable_voucher_entry e LEFT JOIN {master}.account_type a ON a.id=e.account_type_id WHERE e.accounts_payable_voucher_id={int(id)} AND e.is_deleted=0;")
   rows=existing or query(f"SELECT g.account_type_id,g.debit,g.credit,COALESCE(a.code,''),IF(a.id IS NOT NULL AND a.is_deleted=0 AND a.status='active',1,0) FROM {db}.general_ledger g LEFT JOIN {master}.account_type a ON a.id=g.account_type_id WHERE g.source='accounts_payable_voucher' AND g.source_id={int(id)} AND g.is_deleted=0;")
   reason=[]
   if not rows: reason.append('No active child entries or historical APV GL lines')
   elif any(valid!='1' or dr=='NULL' or cr=='NULL' or D(dr)<0 or D(cr)<0 or ((D(dr)>0)==(D(cr)>0)) for _,dr,cr,_,valid in rows):reason.append('Invalid account or debit/credit side')
   else:
    dr=sum(D(x[1]) for x in rows);cr=sum(D(x[2]) for x in rows);pay=sum(D(x[2])-D(x[1]) for x in rows if x[3]=='201-111')
    if dr<=0 or dr!=cr:reason.append(f'Unbalanced historical posting: debit={dr}, credit={cr}')
    if pay!=D(amount):reason.append(f'Payable mismatch: net AP={pay}, header={amount}')
   if reason:exceptions.append([db,'apv',id,'; '.join(reason)])
   else:candidates.append([db,id,'preserve' if existing else 'backfill',kind,origin,amount,status])
  dp_rows=query(f"SELECT d.id,d.amount,d.duration_from,d.duration_to,(SELECT COUNT(*) FROM {db}.general_ledger g WHERE g.source='direct_payment' AND g.source_id=d.id AND g.is_deleted=0),(SELECT COUNT(*) FROM {db}.accounts_payable_voucher v WHERE v.type='direct_payment' AND v.type_id=d.id AND v.is_deleted=0) FROM {db}.direct_payment d WHERE d.is_deleted=0;")
  for id,amount,start,end,posted,linked in dp_rows:
   if int(posted)>0 and int(linked)!=1:exceptions.append([db,'direct_payment',id,'Active direct-payment GL has no unique APV successor'])
   if start!='NULL' and end!='NULL':
    if 'accounts_payable_voucher_amortization_entry' not in tables:
     exceptions.append([db,'amortization',id,'Explicit expense/prepaid allocations have not been migrated'])
    else:
     lines=query(f"SELECT a.amount,a.expense_account_type_id,a.prepaid_account_type_id,IF(e.id IS NOT NULL AND e.is_deleted=0 AND e.status='active' AND p.id IS NOT NULL AND p.is_deleted=0 AND p.status='active',1,0) FROM {db}.accounts_payable_voucher_amortization_entry a JOIN {db}.accounts_payable_voucher v ON v.id=a.accounts_payable_voucher_id AND v.is_deleted=0 AND v.type='direct_payment' AND v.type_id={int(id)} LEFT JOIN {master}.account_type e ON e.id=a.expense_account_type_id LEFT JOIN {master}.account_type p ON p.id=a.prepaid_account_type_id WHERE a.is_deleted=0;")
     if not lines or int(linked)!=1 or any(D(x[0])<=0 or x[1]==x[2] or x[3]!='1' for x in lines) or sum(D(x[0]) for x in lines)!=D(amount):
      exceptions.append([db,'amortization',id,'Missing, invalid, or amount-mismatched explicit allocations'])
  if 'direct_payment_entry' in tables:
   old=query(f"SELECT e.direct_payment_id,e.id,e.account_type_id,e.debit,e.credit,e.is_deleted,COALESCE(a.code,''),IF(a.id IS NOT NULL AND a.is_deleted=0 AND a.status='active',1,0) FROM {db}.direct_payment_entry e LEFT JOIN {master}.account_type a ON a.id=e.account_type_id;")
   legacy_entries.extend([[db]+line for line in old])
   active_by_parent={}
   for line in old:
    if line[5]=='0':active_by_parent.setdefault(line[0],[]).append(line)
   for parent,lines in active_by_parent.items():
    source=query(f"SELECT amount FROM {db}.direct_payment WHERE id={int(parent)};")
    if not source:
     exceptions.append([db,'direct_payment_entries',parent,'Orphan legacy accounting entries'])
    elif any(x[3]=='NULL' or x[4]=='NULL' or D(x[3])<0 or D(x[4])<0 or ((D(x[3])>0)==(D(x[4])>0)) for x in lines):
     exceptions.append([db,'direct_payment_entries',parent,'Invalid legacy debit/credit sides'])
    elif sum(D(x[3]) for x in lines)!=sum(D(x[4]) for x in lines) or sum(D(x[3]) for x in lines)!=D(source[0][0]):
     exceptions.append([db,'direct_payment_entries',parent,'Legacy accounting does not balance/reconcile to direct-payment amount'])
  orphaned=query(f"SELECT DISTINCT g.source_id FROM {db}.general_ledger g LEFT JOIN {db}.direct_payment d ON d.id=g.source_id AND d.is_deleted=0 WHERE g.source='direct_payment' AND g.is_deleted=0 AND d.id IS NULL;")
  for line in orphaned:exceptions.append([db,'direct_payment',line[0],'Active GL has missing/deleted source payment'])
  deps=query(f"SELECT 'trigger',TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{db}' AND ACTION_STATEMENT LIKE '%direct_payment_entry%' UNION ALL SELECT 'routine',ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='{db}' AND ROUTINE_DEFINITION LIKE '%direct_payment_entry%';")
  dependencies.extend([[db]+r for r in deps])
  stats[db]={'active_apvs':len(headers),'active_direct_payments':len(dp_rows),'old_entry_table_present':'direct_payment_entry' in tables}
for name,head,rows in [('exceptions.tsv',['schema','kind','record_id','reason'],exceptions),('candidates.tsv',['schema','apv_id','action','type','type_id','amount','status'],candidates),('installed_dependencies.tsv',['schema','kind','name'],dependencies),('legacy_entries.tsv',['schema','direct_payment_id','entry_id','account_type_id','debit','credit','is_deleted','account_code','currently_active_account'],legacy_entries)]:
 with (out/name).open('w') as f:
  w=csv.writer(f,delimiter='\t');w.writerow(head);w.writerows(rows)
(out/'summary.json').write_text(json.dumps({'exceptions':len(exceptions),'by_kind':dict(Counter(x[1] for x in exceptions)),'schemas':stats},indent=2)+'\n')
print(json.dumps({'exceptions':len(exceptions),'by_kind':dict(Counter(x[1] for x in exceptions)),'output':str(out)}))
raise SystemExit(2 if exceptions else 0)
