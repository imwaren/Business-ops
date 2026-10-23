"""Run after apv_accounting_check.php; reuse only its disposable local fixture."""
import os,re,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
socket=os.environ.get('BILLING_FIXTURE_SOCKET','/private/tmp/billing_deposit_fixture_20261021/mysql.sock')
assert socket.startswith('/private/tmp/')
client=['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults','--socket='+socket,'-uroot','-N','-B','apv_accounting_fixture']
checks=0

def sql(statement):
 p=subprocess.run(client,input=statement,text=True,capture_output=True)
 if p.returncode:raise RuntimeError(p.stderr)
 return p.stdout.strip()
def check(value,label):
 global checks
 if not value:raise AssertionError(label)
 checks+=1
for env in ['prod','uat']:
 text=(root/f'sql/apv_posting_date/install_{env}_branch.sql').read_text()
 # Fixture-only allowlist/master substitution; accounting expressions are untouched.
 text=text.replace('mytemr_master_uat','apv_accounting_fixture').replace('mytemr_master','apv_accounting_fixture')
 text=re.sub(r"IF DATABASE\(\) NOT IN \([^\n]+\) THEN","IF DATABASE() NOT IN ('apv_accounting_fixture') THEN",text)
 sql(text);sql(text)
 for branch in [1,2,3,4,5,6,7]:
  sql(f'DROP FUNCTION gl_branch_id; CREATE FUNCTION gl_branch_id() RETURNS INT DETERMINISTIC RETURN {branch};')
  for apv_type in ['cash_advance','refund','change_fund','petty_cash','liquidation','reimbursement','replenishment']:
   sql(f"UPDATE accounts_payable_voucher SET type='{apv_type}',type_id=700,approved_on='2026-10-22 23:45:00',added_on='2026-10-21 09:00:00',due_date='2026-10-31' WHERE id=700; CALL gl_post_apv(700); CALL gl_post_apv(700);")
   check(sql("SELECT COUNT(*),MIN(date),MAX(date),MIN(branch_id),SUM(debit),SUM(credit) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0")==f'2\t2026-10-22\t2026-10-22\t{branch}\t100.0000\t100.0000',f'{env} branch {branch} {apv_type} GL approval date')
   check(sql("SELECT COUNT(*),MIN(date),MAX(date) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0")=='1\t2026-10-22\t2026-10-22',f'{env} branch {branch} {apv_type} JE date')
   sql('UPDATE accounts_payable_voucher SET approved_on=NULL WHERE id=700;')
   check(sql("SELECT MIN(date),MAX(date) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id=700 AND is_deleted=0")=='2026-10-21\t2026-10-21','Missing approval uses creation date')
   check(sql("SELECT COUNT(*),SUM(i.debit),SUM(i.credit) FROM journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id WHERE j.source='accounts_payable_voucher' AND j.source_id=700 AND j.is_deleted=0 AND i.is_deleted=0")=='2\t100.0000\t100.0000','JE items stay balanced and unique')
sql('DROP FUNCTION gl_branch_id; CREATE FUNCTION gl_branch_id() RETURNS INT DETERMINISTIC RETURN 2;')
print(f'PASS {checks} clinic/warehouse/pharmacy APV date checks')
