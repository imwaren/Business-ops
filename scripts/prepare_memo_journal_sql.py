#!/usr/bin/env python3
"""Build a private scoped package. No encryption key, posting calls or live connections."""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
OBJECTS = [('FUNCTION', 'gl_journal_base_remark'), ('PROCEDURE', 'gl_je_from_gl'), ('PROCEDURE', 'gl_je_rebuild')]


def extract(source, kind, name):
    match = re.search(r'CREATE ' + kind + ' ' + name + r'\b.*?END\$\$', source, re.S)
    return match.group()[:-2] if match else None


def build(output):
    os.umask(0o077)
    output.mkdir(mode=0o700, parents=True, exist_ok=False)
    manifest = {}
    for environment, suffix in [('uat', '_uat'), ('prod', '')]:
        path = 'sql/general_ledger_triggers_master' + suffix + '.sql'
        source = (ROOT / path).read_text()
        baseline = subprocess.check_output(['git', 'show', 'HEAD:' + path], cwd=ROOT, text=True)
        manifest[environment] = [dict(kind=kind, name=name, definition=extract(source, kind, name),
                                      baseline=extract(baseline, kind, name)) for kind, name in OBJECTS]
        assert all(obj['definition'] for obj in manifest[environment])
        assert '__GL_ENCRYPTION_KEY_HEX__' not in json.dumps(manifest[environment])
        sql = '-- Scoped journal-reference routines only. No posting or rebuild calls.\nDELIMITER $$\n'
        sql += '\n'.join('DROP ' + obj['kind'] + ' IF EXISTS ' + obj['name'] + '$$\n' + obj['definition'] + '$$'
                         for obj in manifest[environment]) + '\nDELIMITER ;\n'
        (output / ('install_' + environment + '.sql')).write_text(sql)
    (output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    shutil.copyfile(ROOT / 'scripts/deploy_memo_journal_uat.py', output / 'deploy.py')
    shutil.copyfile(ROOT / 'sql/gl_remark_names/deploy.py', output / 'gl_remark_deploy_support.py')
    print('Prepared three scoped routines per SQL variant; no database changes.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    build(parser.parse_args().output)
