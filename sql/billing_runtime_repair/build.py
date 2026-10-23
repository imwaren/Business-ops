"""Build a dependency-complete, master-only runtime repair; never executes SQL."""
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(__file__).resolve().parent

def definition(text,kind,name):
 m=re.search(r'DROP '+kind+' IF EXISTS '+name+r'\$\$.*?END\$\$',text,re.S)
 if not m:raise ValueError('Missing canonical definition '+name)
 return m[0]
helper=definition((ROOT/'sql/fragments/general_ledger_remarks_master.sql').read_text(),'FUNCTION','gl_journal_base_remark')
# Narrow installers must carry their helper rather than assume its deployment.
for path in ['sql/billing_collection_repair/install.sql','sql/billing_collection_repair/remarks_install.sql','sql/billing_ar_repair/install_prod.sql','sql/billing_ar_repair/install_uat.sql']:
 p=ROOT/path;text=p.read_text()
 text=re.sub(r'DROP FUNCTION IF EXISTS gl_journal_base_remark\$\$.*?END\$\$\s*','',text,flags=re.S)
 text=re.sub(r'-- BEGIN BILLING BASE HELPER PREREQUISITE.*?-- END BILLING BASE HELPER PREREQUISITE\n','',text,flags=re.S)
 prerequisite="""-- BEGIN BILLING BASE HELPER PREREQUISITE
DROP PROCEDURE IF EXISTS assert_billing_base_helper$$
CREATE PROCEDURE assert_billing_base_helper()
BEGIN
 IF NOT EXISTS (SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_remark_append' AND ROUTINE_TYPE='FUNCTION') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Install gl_remark_append before billing journal helpers';
 END IF;
END$$
CALL assert_billing_base_helper()$$
DROP PROCEDURE assert_billing_base_helper$$
-- END BILLING BASE HELPER PREREQUISITE
"""
 text=text.replace('DELIMITER $$','DELIMITER $$\n'+prerequisite+'\n'+helper,1)
 p.write_text(text)
source=(ROOT/'sql/general_ledger_triggers_master.sql').read_text()
names=[('FUNCTION','gl_journal_base_remark'),('FUNCTION','gl_billing_payment_journal_remark'),('PROCEDURE','gl_je_from_gl'),('PROCEDURE','gl_je_rebuild'),('PROCEDURE','gl_post_billing_payment'),('PROCEDURE','gl_post_direct_payment')]
external=['gl_account_id','gl_billing_payer_label','gl_billing_mop_code','gl_clear_billing_movement','gl_enrich_remark','gl_remark_append','gl_shared_clear','gl_shared_post_direct_payment']
guard="""DELIMITER $$
DROP PROCEDURE IF EXISTS assert_billing_runtime_install$$
CREATE PROCEDURE assert_billing_runtime_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_master','mytemr_master_uat') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Billing runtime repair requires the selected master database';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
  AND ROUTINE_NAME IN ("""+','.join("'"+n+"'" for n in external)+'))<>'+str(len(external))+""" THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing billing runtime helper prerequisites; stop before installation';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('billing','billing_entry','billing_payment','billing_payment_entry','general_ledger','journal_entry','journal_entry_item','direct_payment','branch','apv_accounting_cutover'))<>10 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing billing/accounting/shared dispatch tables';
 END IF;
END$$
CALL assert_billing_runtime_install()$$
DROP PROCEDURE assert_billing_runtime_install$$
DELIMITER ;
"""
(OUT/'assert.sql').write_text(guard)
(OUT/'install.sql').write_text('-- Scoped runtime repair. No source DML, posting calls, or backfill.\nSET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n'+guard+'\nDELIMITER $$\n'+'\n\n'.join(definition(source,k,n) for k,n in names)+'\nDELIMITER ;\n')
