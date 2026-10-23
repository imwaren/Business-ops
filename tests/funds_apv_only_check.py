"""Local MariaDB only: fund sources preserve history; APVs own accounting."""
import runpy
from pathlib import Path
ctx=runpy.run_path(str(Path(__file__).with_name('shared_funds_check.py')))
sql,check,root=ctx['sql'],ctx['check'],ctx['root'];n=0
funds=['change_fund','petty_cash','liquidation','reimbursement','replenishment']
children={'liquidation':'liquidation_detail','reimbursement':'reimbursement_item','replenishment':'replenishment_item'}
start_checks=check.__globals__['checks']
sources=funds+list(children.values());source_list=','.join("'"+s+"'" for s in sources)
def snapshot():return sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;')
def history():return sql('SELECT * FROM general_ledger WHERE source IN ('+source_list+') ORDER BY id; SELECT * FROM journal_entry WHERE source IN ('+source_list+') ORDER BY id; SELECT i.* FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source IN ('+source_list+') ORDER BY i.id;')
# Retain active/deleted automated rows, migrated rows, and manual journals.
for source in sources:
 i=1
 sql(f"INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,is_migrated,is_deleted) VALUES(0,'{source}',{i},80,'2026-10-01',10,0,0,0),(0,'{source}',{i},80,'2026-10-01',9,0,1,0),(0,'{source}',{i},80,'2026-10-01',8,0,0,1); INSERT INTO journal_entry(cost_center_id,date,source,source_id,is_migrated,status) VALUES(0,'2026-10-01','{source}',{i},0,'approved'),(0,'2026-10-01','{source}',{i},1,'approved'); INSERT INTO journal_entry_item(journal_entry_id,account_type_id,debit,credit,type) SELECT id,80,10,0,'Automated' FROM journal_entry WHERE source='{source}' AND source_id={i};")
before=snapshot();kept=history();ids={}
for fund in funds:
 sql(f"INSERT INTO {fund}(cost_center_id,amount,account_type_id,status,added_by) VALUES(20,100,80,'pending',1);")
 sid=sql('SELECT MAX(id) FROM '+fund);ids[fund]=sid
 sql(f"UPDATE {fund} SET status='approved',amount=110 WHERE id={sid}; CALL gl_shared_post_{fund}({sid}); CALL gl_shared_post_{fund}({sid}); UPDATE {fund} SET is_deleted=1 WHERE id={sid}; UPDATE {fund} SET is_deleted=0,amount=100 WHERE id={sid};")
 if fund in children:
  child=children[fund]
  sql(f"INSERT INTO {child}({fund}_id,account_type_id,amount,added_by) VALUES({sid},80,100,1);")
  cid=sql('SELECT MAX(id) FROM '+child)
  sql(f"UPDATE {child} SET amount=125 WHERE id={cid}; UPDATE {child} SET {fund}_id=1 WHERE id={cid}; UPDATE {child} SET is_deleted=1 WHERE id={cid}; DELETE FROM {child} WHERE id={cid};")
 check(before==snapshot(),'shared '+fund+' lifecycle leaves all accounting unchanged')
# Reinstallation must not restore any disabled accounting, including the older release.
for kind in ['master','branch']:
 sql((root/f'sql/funds_apv_only/install_{kind}.sql').read_text())
 sql((root/f'sql/funds_apv_only/install_{kind}.sql').read_text())
 sql((root/f'sql/cash_advance_apv_only/install_{"master" if kind=="master" else "clinic"}.sql').read_text())
 check(before==snapshot(),kind+' installer leaves historical rows unchanged')
 for fund in funds:
  prefix='gl_shared_post_' if kind=='master' else 'gl_post_'
  sql(f'CALL {prefix}{fund}(1); CALL {prefix}{fund}(1); UPDATE {fund} SET is_deleted=1 WHERE id=1; UPDATE {fund} SET is_deleted=0 WHERE id=1;')
  sql(f"UPDATE {fund} SET amount=101 WHERE id={ids[fund]}; UPDATE {fund} SET amount=100 WHERE id={ids[fund]};")
 check(before==snapshot(),kind+' compatibility calls and edits preserve history')
# Simulate physical branch source lifecycles for all seven identities. These SQL paths do not require gl_settings.
for branch in [1,2,3,4,5,6,7]:
 for fund in funds:
  sql(f"INSERT INTO {fund}(cost_center_id,amount,account_type_id,status) VALUES({branch},100,80,'pending'); SET @sid=LAST_INSERT_ID(); UPDATE {fund} SET status='approved',amount=120 WHERE id=@sid; UPDATE {fund} SET is_deleted=1 WHERE id=@sid; CALL gl_post_{fund}(@sid);")
 check(before==snapshot(),'physical branch '+str(branch)+' sources do not post')
# Old callers can still drain queues without dispatching disabled fund sources.
statement='CREATE TEMPORARY TABLE gl_bf_ids(id INT);'
for fund in funds:statement+=f"INSERT INTO gl_bf_ids VALUES(1),(2); CALL gl_bf_drain('{fund}');"
check(sql(statement+'SELECT COUNT(*) FROM gl_bf_ids;')=='0','removed kinds drain safely without posting')
check(before==snapshot(),'backfill dispatch leaves history unchanged')
for i,fund in enumerate(funds,1001):
 sql(f"SET @apv_entry_sync=1; INSERT INTO accounts_payable_voucher(id,cost_center_id,type,type_id,amount,status,approved_on,added_on,due_date,added_by) VALUES({i},20,'{fund}',{ids[fund]},100,'approved','2026-10-22','2026-10-21','2026-10-31',1); INSERT INTO accounts_payable_voucher_entry(accounts_payable_voucher_id,account_type_id,debit,credit,added_by,added_on) VALUES({i},80,100,0,1,NOW()),({i},57,0,100,1,NOW()); SET @apv_entry_sync=0; CALL gl_post_apv({i}); CALL gl_post_apv({i});")
 check(sql(f"SELECT COUNT(*),SUM(debit),SUM(credit),MIN(date) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id={i} AND is_deleted=0")=='2\t100.0000\t100.0000\t2026-10-22',fund+' APV GL balanced and approval-dated')
 check(sql(f"SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id={i} AND is_deleted=0")=='1',fund+' APV one active JE')
 check(sql(f"SELECT COUNT(*),SUM(it.debit),SUM(it.credit) FROM journal_entry_item it JOIN journal_entry j ON j.id=it.journal_entry_id WHERE j.source='accounts_payable_voucher' AND j.source_id={i} AND j.is_deleted=0 AND it.is_deleted=0")=='2\t100.0000\t100.0000',fund+' APV matching balanced items')
 sql(f"INSERT INTO accounts_payable_voucher_payment(id,account_type_id,is_deleted) VALUES({i},13,0); INSERT INTO accounts_payable_voucher_payment_detail(accounts_payable_voucher_id,accounts_payable_voucher_payment_id,amount,date,added_by) VALUES({i},{i},100,'2026-10-23',1);")
 did=sql('SELECT MAX(id) FROM accounts_payable_voucher_payment_detail')
 check(sql(f"SELECT COUNT(*),SUM(debit),SUM(credit),MIN(date) FROM general_ledger WHERE source='accounts_payable_voucher_payment_detail' AND source_id={did} AND is_deleted=0")=='2\t100.0000\t100.0000\t2026-10-23',fund+' payment remains balanced on payment date')
 check(kept==history(),fund+' APV does not retire preexisting direct-source history')
for filename in ['general_ledger_triggers.sql','general_ledger_triggers_uat.sql','general_ledger_triggers_master.sql','general_ledger_triggers_master_uat.sql']:
 text=(root/'sql'/filename).read_text()
 for fund in funds:
  check('CALL gl_post_'+fund+'(' not in text and 'CALL gl_shared_post_'+fund+'(' not in text,filename+' has no direct dispatch '+fund)
check(sql("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME REGEXP '^trg_gl_(change_fund|petty_cash|liquidation|liquidation_detail|reimbursement|reimbursement_item|replenishment|replenishment_item)_'")=='0','all direct parent/child hooks absent')
print(f"PASS {check.__globals__['checks']-start_checks} fund APV-only lifecycle, history and posting checks")
