"""Build scoped refund APV installers without executing SQL or embedding secrets."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
OUT = Path(__file__).resolve().parent


def definition(source, kind, name):
    match = re.search(r'DROP ' + kind + ' IF EXISTS ' + name + r'\$\$.*?END\$\$', source, re.S)
    if not match:
        raise ValueError('Missing release definition: ' + name)
    return match.group()


def guard(environment, master):
    suffix = '_uat' if environment == 'uat' else ''
    schemas = ['mytemr_master' + suffix] if master else [
        name + suffix for name in ['mytemr_diagnostic', 'mytemr_diagnostic_mobile',
                                  'mytemr_diagnostic_mactan', 'mytemr_diagnostic_conso',
                                  'mytemr_diagnostic_mantlewood']]
    names = ['gl_patient_display_name', 'gl_apv_is_legacy', 'gl_post_apv']
    names += ['gl_shared_clear', 'gl_master_apv_context', 'gl_billing_transaction_no'] if master else [
        'gl_clear', 'gl_remark_context', 'gl_remark_append', 'gl_transaction_item_names']
    schema_list = ','.join("'" + name + "'" for name in schemas)
    routine_list = ','.join("'" + name + "'" for name in names)
    return f"""DELIMITER $$
DROP PROCEDURE IF EXISTS assert_refund_apv_posting_install$$
CREATE PROCEDURE assert_refund_apv_posting_install()
BEGIN
    IF DATABASE() NOT IN ({schema_list}) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong schema/environment for refund APV installer';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
        AND ROUTINE_NAME IN ({routine_list})) <> {len(names)} THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing existing APV/remark prerequisites; stop and reconcile';
    END IF;
END$$
CALL assert_refund_apv_posting_install()$$
DROP PROCEDURE assert_refund_apv_posting_install$$
DELIMITER ;
"""


for environment, suffix in [('prod', ''), ('uat', '_uat')]:
    for master in [False, True]:
        source = (ROOT / f"sql/general_ledger_triggers{'_master' if master else ''}{suffix}.sql").read_text()
        names = [('FUNCTION', 'gl_master_apv_context' if master else 'gl_remark_context'),
                 ('PROCEDURE', 'gl_post_apv')]
        definitions = '\n\n'.join(definition(source, kind, name) for kind, name in names)
        target = OUT / f"install_{environment}{'_master' if master else ''}.sql"
        target.write_text('-- Scoped refund APV definitions only. No posting/rebuild/backfill calls.\n'
                          'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n'
                          + guard(environment, master) + '\nDELIMITER $$\n' + definitions + '\nDELIMITER ;\n')
