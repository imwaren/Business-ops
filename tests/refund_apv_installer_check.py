"""Run after general_ledger_remark_names_check.py; uses its disposable local schemas."""
import hashlib
import os
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
SOCKET = os.environ.get('BILLING_FIXTURE_SOCKET', '/private/tmp/company_cleanup_db/mysql.sock')
assert SOCKET.startswith('/private/tmp/'), 'A disposable local socket is required'
CLIENT = ['/Applications/XAMPP/xamppfiles/bin/mysql', '--no-defaults', '--socket=' + SOCKET,
          '-u', os.environ.get('BILLING_FIXTURE_USER', 'myt_waren'), '--batch', '--skip-column-names']
checks = 0


def sql(statement, database, failure=False):
    result = subprocess.run(CLIENT + [database], input=statement, text=True, capture_output=True)
    if failure:
        assert result.returncode and 'Wrong schema/environment' in result.stderr, result.stderr
    else:
        assert result.returncode == 0, result.stderr
    return result.stdout


def history(database):
    return hashlib.sha256(sql('SELECT * FROM general_ledger ORDER BY id; '
                             'SELECT * FROM journal_entry ORDER BY id; '
                             'SELECT * FROM journal_entry_item ORDER BY id;', database).encode()).hexdigest()


for environment, suffix in [('prod', ''), ('uat', '_uat')]:
    mapping = {'mytemr_master' + suffix: f'gl_names_{environment}_master'}
    for branch, name in {1: 'mobile', 2: '', 3: 'mactan', 4: 'conso', 7: 'mantlewood'}.items():
        mapping['mytemr_diagnostic' + ('_' + name if name else '') + suffix] = f'gl_names_{environment}_branch_{branch}'
    for master in [False, True]:
        source = (ROOT / f"sql/refund_apv_posting/install_{environment}{'_master' if master else ''}.sql").read_text()
        for original, fixture in sorted(mapping.items(), key=lambda pair: len(pair[0]), reverse=True):
            source = re.sub(r'\b' + original + r'\b', fixture, source)
        database = mapping['mytemr_master' + suffix] if master else mapping['mytemr_diagnostic' + suffix]
        wrong = mapping['mytemr_diagnostic' + suffix] if master else mapping['mytemr_master' + suffix]
        before = history(database)
        sql(source, database)
        sql(source, database)
        assert history(database) == before, 'Scoped installation modified accounting history'
        checks += 1
        definition = sql('SHOW CREATE PROCEDURE gl_post_apv;', wrong)
        sql(source, wrong, failure=True)
        assert sql('SHOW CREATE PROCEDURE gl_post_apv;', wrong) == definition, 'Wrong-schema guard changed posting'
        sql('DROP PROCEDURE IF EXISTS assert_refund_apv_posting_install;', wrong)
        checks += 1
print(f'PASS {checks} scoped refund APV installer checks (four variants, repeated installation and schema guards).')
