"""Generate single-routine APV date installers; no database execution."""
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(__file__).resolve().parent
for env,suffix in [('prod',''),('uat','_uat')]:
 for kind,master in [('branch',False),('master',True)]:
  source=(ROOT/f'sql/general_ledger_triggers{"_master" if master else ""}{suffix}.sql').read_text()
  definition=re.search(r'DROP PROCEDURE IF EXISTS gl_post_apv\$\$.*?END\$\$',source,re.S)[0]
  bases=['mytemr_master'] if master else ['mytemr_diagnostic','mytemr_diagnostic_mobile','mytemr_diagnostic_mactan','mytemr_diagnostic_conso','mytemr_diagnostic_mantlewood','mytemr_oakridge_warehouse','mytemr_oakridge_pharmacy']
  schemas=','.join("'"+base+suffix+"'" for base in bases)
  external=['gl_apv_is_legacy','gl_shared_clear','gl_shared_je'] if master else ['gl_apv_is_legacy','gl_clear','gl_branch_id']
  names=','.join("'"+name+"'" for name in external)
  guard=f'''DELIMITER $$
DROP PROCEDURE IF EXISTS assert_apv_date_install$$
CREATE PROCEDURE assert_apv_date_install()
BEGIN
 IF DATABASE() NOT IN ({schemas}) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong environment/schema for APV posting date installer';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='accounts_payable_voucher' AND COLUMN_NAME IN ('approved_on','added_on'))<>2 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='APV approval/creation timestamp columns are required';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ({names}))<>{len(external)} THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing APV posting prerequisites';
 END IF;
END$$
CALL assert_apv_date_install()$$
DROP PROCEDURE assert_apv_date_install$$
'''
  (OUT/f'install_{env}_{kind}.sql').write_text('-- Approval date first, creation fallback. Definitions only; no repost.\nSET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n'+guard+definition+'\nDELIMITER ;\n')
