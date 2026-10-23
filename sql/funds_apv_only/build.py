"""Generate scoped fund-posting disable SQL; no database execution."""
from pathlib import Path
import re
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(__file__).resolve().parent
FUNDS=['change_fund','petty_cash','liquidation','reimbursement','replenishment']
TABLES=FUNDS+['liquidation_detail','reimbursement_item','replenishment_item']
for kind,path,prefix in [('branch','sql/general_ledger_triggers.sql','gl_post_'),('master','sql/shared_funds/posting.sql','gl_shared_post_')]:
 source=(ROOT/path).read_text()
 names=[prefix+name for name in FUNDS]+(['gl_bf_drain','gl_backfill_all'] if kind=='branch' else [])
 drops='\n'.join('DROP TRIGGER IF EXISTS trg_gl_'+table+'_'+event+'$$' for table in TABLES for event in ['ai','au','ad'])
 definitions='\n\n'.join(re.search(r'DROP PROCEDURE IF EXISTS '+name+r'\$\$.*?END\$\$',source,re.S)[0] for name in names)
 (OUT/f'install_{kind}.sql').write_text('-- Fund accounting through APVs only. Definitions only; no history cleanup/repost.\nDELIMITER $$\n'+drops+'\n\n'+definitions+'\nDELIMITER ;\n')
