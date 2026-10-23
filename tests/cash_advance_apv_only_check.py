"""Isolated local checks: direct cash advances never post; APVs remain enabled."""
import runpy
from pathlib import Path
ctx=runpy.run_path(str(Path(__file__).with_name('shared_funds_check.py')))
sql,check,root=ctx['sql'],ctx['check'],ctx['root']
# Existing automatic, migrated and manual history must survive compatibility calls.
sql("INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,is_migrated) VALUES(0,'cash_advance',1,80,'2026-10-21',9,0,0),(0,'cash_advance',1,80,'2026-10-21',8,0,1); INSERT INTO journal_entry(cost_center_id,date,source,source_id,is_migrated,status) VALUES(0,'2026-10-21','cash_advance',1,0,'approved'),(0,'2026-10-21','cash_advance',1,1,'approved'),(0,'2026-10-21',NULL,NULL,0,'approved');")
before=sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;')
sql('CALL gl_shared_post_cash_advance(1); CALL gl_shared_post_cash_advance(1); UPDATE cash_advance SET amount=321,status="approved" WHERE id=1; UPDATE cash_advance SET is_deleted=1 WHERE id=1;')
check(before==sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;'),'shared compatibility calls and source edits preserve all history')
# Install the scoped master release twice; no backfill or source changes.
sql((root/'sql/cash_advance_apv_only/install_master.sql').read_text())
sql((root/'sql/cash_advance_apv_only/install_master.sql').read_text())
check(before==sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;'),'scoped master install preserves data')
sql('INSERT INTO liquidation(cost_center_id,cash_advance_id,status,amount) VALUES(20,1,"approved",10); CALL gl_shared_post_liquidation(LAST_INSERT_ID());')
check(before==sql('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;'),'linked liquidation does not recreate or retire cash advance history')
# Cash-advance APV uses the unchanged explicit-entry and payment dispatch.
sql("SET @apv_entry_sync=1; INSERT INTO accounts_payable_voucher(cost_center_id,type,type_id,amount,status,date,added_by) VALUES(23,'cash_advance',2,100,'approved','2026-10-21',1); SET @vid=LAST_INSERT_ID(); INSERT INTO accounts_payable_voucher_entry(accounts_payable_voucher_id,account_type_id,debit,credit,added_by,added_on) VALUES(@vid,80,100,0,1,NOW()),(@vid,57,0,100,1,NOW()); SET @apv_entry_sync=0; CALL gl_post_apv(@vid); CALL gl_post_apv(@vid);")
vid=sql('SELECT MAX(id) FROM accounts_payable_voucher')
check(sql(f"SELECT COUNT(*),SUM(debit),SUM(credit) FROM general_ledger WHERE source='accounts_payable_voucher' AND source_id={vid} AND is_deleted=0")=='2\t100.0000\t100.0000','cash advance APV balanced without duplicate rows')
check(sql(f"SELECT COUNT(*) FROM journal_entry WHERE source='accounts_payable_voucher' AND source_id={vid} AND is_deleted=0")=='1','one cash advance APV JE')
# Clinic scoped routines compile and disable both legacy hooks/backfill dispatch.
sql('CREATE FUNCTION gl_branch_id() RETURNS INT DETERMINISTIC RETURN 2;')
sql((root/'sql/cash_advance_apv_only/install_clinic.sql').read_text())
clinic_before=sql("SELECT * FROM general_ledger WHERE source='cash_advance' ORDER BY id; SELECT * FROM journal_entry WHERE source='cash_advance' ORDER BY id;")
sql('CALL gl_post_cash_advance(1); CALL gl_post_cash_advance(1); INSERT INTO cash_advance(cost_center_id,status,amount,account_type_id) VALUES(2,"pending",100,80); SET @cid=LAST_INSERT_ID(); UPDATE cash_advance SET status="approved",amount=200 WHERE id=@cid; UPDATE cash_advance SET is_deleted=1 WHERE id=@cid; CALL gl_post_liquidation(999999);')
check(clinic_before==sql("SELECT * FROM general_ledger WHERE source='cash_advance' ORDER BY id; SELECT * FROM journal_entry WHERE source='cash_advance' ORDER BY id;"),'clinic lifecycle and liquidation preserve direct history without new postings')
check(sql("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('trg_gl_cash_advance_ai','trg_gl_cash_advance_au')")=='0','no cash advance hooks')
check(sql('CREATE TEMPORARY TABLE gl_bf_ids(id INT); INSERT INTO gl_bf_ids VALUES(123); CALL gl_bf_drain("cash_advance"); SELECT COUNT(*) FROM gl_bf_ids;')=='0','old backfill callers drain without posting')
for filename in ['general_ledger_triggers.sql','general_ledger_triggers_uat.sql','general_ledger_triggers_master.sql','general_ledger_triggers_master_uat.sql']:
    text=(root/'sql'/filename).read_text()
    check('CREATE TRIGGER trg_gl_cash_advance_' not in text and 'CALL gl_post_cash_advance(' not in text and 'CALL gl_shared_post_cash_advance(' not in text,filename+' has no direct dispatch')
print('PASS cash-advance APV-only checks including scoped MariaDB installers')
