"""Scoped receipt VAT/parent-reference release. Never connects to a database."""
from pathlib import Path
import re
ROOT = Path(__file__).resolve().parents[2]
OUT = Path(__file__).resolve().parent

def extract(source, kind, name):
    return re.search(r'CREATE ' + kind + ' ' + name + r'\b.*?END\$\$', source, re.S).group()

for suffix, env in [('', 'prod'), ('_uat', 'uat')]:
    for master in [False, True]:
        source = (ROOT / f'sql/general_ledger_triggers{suffix}.sql').read_text()
        if master:
            source = (ROOT / f'sql/general_ledger_triggers_master{suffix}.sql').read_text()
        objects = [('FUNCTION', 'gl_master_apv_context'), ('PROCEDURE', 'gl_shared_post_receipt_note')] if master else [('FUNCTION', 'gl_remark_context'), ('FUNCTION', 'gl_enrich_remark'), ('PROCEDURE', 'gl_post_receipt_note')]
        bodies = [(kind, name, extract(source, kind, name)) for kind, name in objects]
        required = sorted(set(re.findall(r'\b(gl_\w+)\s*\(', '\n'.join(b for _, _, b in bodies))) - {n for _, n in objects})
        if not master:
            required = sorted(set(required) | {'gl_je_from_gl'})
        schemas = ['mytemr_master'] if master else ['mytemr_diagnostic', 'mytemr_diagnostic_mobile', 'mytemr_diagnostic_mactan', 'mytemr_diagnostic_conso', 'mytemr_diagnostic_mantlewood', 'mytemr_oakridge_warehouse', 'mytemr_oakridge_pharmacy']
        names = ','.join("'" + n + "'" for n in required)
        allowed = ','.join("'" + n + suffix + "'" for n in schemas)
        sql = f'''-- Future receipt postings only. No data, triggers, schema or history are changed.
DELIMITER $$
DROP PROCEDURE IF EXISTS assert_receipt_note_vat_install$$
CREATE PROCEDURE assert_receipt_note_vat_install()
BEGIN
 IF DATABASE() NOT IN ({allowed}) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong receipt VAT target environment/path';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ({names})) <> {len(required)} THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing receipt VAT helper dependencies; inspect installed accounting path';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ({','.join(repr(n) for _,n in objects)})) <> {len(objects)} THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt accounting path not installed; do not introduce a new posting path';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='receipt_note_item' AND COLUMN_NAME='qty') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt posting requires receipt_note_item.qty';
 END IF;
 IF (SELECT COUNT(*) FROM account_type WHERE code='106-000' AND parent_id IS NULL AND is_deleted=0) <> 1
  OR NOT EXISTS (SELECT 1 FROM account_type WHERE code='106-000' AND parent_id IS NULL AND is_deleted=0 AND status='active')
  OR gl_account_id('106-000') IS NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Require one active root INPUT VAT mapping for code 106-000';
 END IF;
END$$
CALL assert_receipt_note_vat_install()$$
DROP PROCEDURE assert_receipt_note_vat_install$$
'''
        for kind, name, body in bodies:
            sql += f'DROP {kind} IF EXISTS {name}$$\n{body}\n'
        sql += 'DELIMITER ;\n'
        (OUT / f'install_{env}{"_master" if master else ""}.sql').write_text(sql)
