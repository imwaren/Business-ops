#!/usr/bin/env python3
"""Build a scoped, unrendered remark release without executing SQL."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
EXTRA = {
    'diagnostic': [('FUNCTION', 'gl_item_history_remarks'), ('PROCEDURE', 'gl_rebuild_txn_je'),
                   ('PROCEDURE', 'gl_je_from_gl'), ('PROCEDURE', 'gl_post_apv_payment_detail')],
    'master': [('PROCEDURE', 'gl_je_from_gl'), ('PROCEDURE', 'gl_je_rebuild'),
               ('PROCEDURE', 'gl_post_billing_entry')],
}


def extract(source, kind, name):
    match = re.search(r'DROP ' + kind + r' IF EXISTS ' + name + r'\$\$\s*CREATE ' + kind
                      + r' ' + name + r'\b.*?END\$\$', source, re.S)
    if not match:
        raise ValueError('Missing definition: ' + name)
    return match.group()


def build(environment, kind):
    suffix = '_uat' if environment == 'uat' else ''
    path = 'sql/general_ledger_triggers' + ('_master' if kind == 'master' else '') + suffix + '.sql'
    source = (ROOT / path).read_text()
    baseline = subprocess.check_output(['git', 'show', 'HEAD:' + path], cwd=ROOT, text=True)
    helpers = source[source.index('-- BEGIN SHARED REMARK HELPERS'):source.index('-- END SHARED REMARK HELPERS')]
    objects = [('FUNCTION', name) for name in re.findall(r'CREATE FUNCTION (\w+)', helpers)] + EXTRA[kind]
    objects.append(('TRIGGER', 'trg_gl_general_ledger_bi'))
    definitions = [extract(source, object_kind, name) for object_kind, name in objects]
    manifest = {'environment': environment, 'kind': kind, 'objects': []}
    for (object_kind, name), definition in zip(objects, definitions):
        old = extract(baseline, object_kind, name) if re.search(r'CREATE ' + object_kind + ' ' + name + r'\b', baseline) else None
        manifest['objects'].append({'kind': object_kind, 'name': name, 'definition': definition,
                                    'baseline_definition': old})
    sql = '-- Scoped remark deployment. No posting/rebuild/backfill calls.\nSET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n'
    sql += (ROOT / 'sql/migrations/journal_entry_remark_names.sql').read_text()
    sql += '\nDELIMITER $$\n' + '\n\n'.join(definitions) + '\nDELIMITER ;\n'
    return sql, manifest


def main():
    output = Path(sys.argv[1])
    output.mkdir(parents=True, exist_ok=True)
    manifest = {}
    for environment in ['uat', 'prod']:
        for kind in ['diagnostic', 'master']:
            sql, details = build(environment, kind)
            name = 'install_' + environment + '_' + kind + '.sql'
            (output / name).write_text(sql)
            details['sha256'] = hashlib.sha256(sql.encode()).hexdigest()
            manifest[name] = details
    (output / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    print('Built four scoped installers; no SQL executed.')


if __name__ == '__main__':
    main()
