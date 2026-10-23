#!/usr/bin/env python3
"""Prepare scoped local SQL; never connects to a database or invokes a poster."""
import argparse
import hashlib
import json
from pathlib import Path
import re

root = Path(__file__).resolve().parents[2]
objects = [('FUNCTION', 'gl_item_history_remarks'), ('PROCEDURE', 'gl_post_item_history'),
           ('TRIGGER', 'trg_gl_item_history_ai'), ('TRIGGER', 'trg_gl_item_history_au')]


def extract(source, kind, name):
    match = re.search(r'DROP ' + kind + r' IF EXISTS ' + name + r'\$\$\s*CREATE ' + kind
                      + r' ' + name + r'\b.*?END\$\$', source, re.S)
    if not match:
        raise ValueError('Missing SQL definition: ' + name)
    return match.group()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    manifest = {}
    for name, source in [('schema.sql', root / 'sql/migrations/branch_item_blindsheet.sql'),
                         ('inventory_history.sql', root / 'sql/blindsheet/inventory_history.sql')]:
        content = source.read_text()
        (args.output / name).write_text(content)
        manifest[name] = {'sha256': hashlib.sha256(content.encode()).hexdigest()}
    for environment in ['uat', 'prod']:
        suffix = '_uat' if environment == 'uat' else ''
        source = (root / ('sql/general_ledger_triggers' + suffix + '.sql')).read_text()
        content = '-- Scoped blindsheet accounting support; no posting or backfill calls.\nDELIMITER $$\n'
        content += '\n\n'.join(extract(source, kind, name) for kind, name in objects)
        content += '\nDELIMITER ;\n'
        name = 'accounting_' + environment + '.sql'
        (args.output / name).write_text(content)
        manifest[name] = {'sha256': hashlib.sha256(content.encode()).hexdigest(),
                          'objects': [{'kind': kind, 'name': name} for kind, name in objects]}
    (args.output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print('Prepared scoped blindsheet SQL. No database changes executed.')


if __name__ == '__main__':
    main()
