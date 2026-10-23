"""Build scoped, data-preserving cash-advance posting disable installers."""
from pathlib import Path
import re
ROOT = Path(__file__).resolve().parents[2]
OUT = Path(__file__).resolve().parent

def routine(source, name):
    return re.search(r'DROP PROCEDURE IF EXISTS '+name+r'\$\$\s*CREATE PROCEDURE '+name+r'\b.*?END\$\$', source, re.S).group(0)

for flavor, path, names in [
    ('clinic', ROOT/'sql/general_ledger_triggers.sql', ['gl_post_cash_advance','gl_post_liquidation','gl_bf_drain','gl_backfill_all']),
    ('master', ROOT/'sql/shared_funds/posting.sql', ['gl_shared_post_cash_advance','gl_shared_post_liquidation']),
]:
    source=path.read_text()
    header='-- Cash advances post through APVs only. No data updates or historical cleanup.\nDELIMITER $$\n'
    drops='DROP TRIGGER IF EXISTS trg_gl_cash_advance_ai$$\nDROP TRIGGER IF EXISTS trg_gl_cash_advance_au$$\n'
    (OUT/f'install_{flavor}.sql').write_text(header+drops+'\n'+'\n\n'.join(routine(source,n) for n in names)+'\nDELIMITER ;\n')
