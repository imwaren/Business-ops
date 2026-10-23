#!/usr/bin/env python3
"""Build an environment-scoped accounting package; never connects or invokes a poster."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
EXCLUDED = {'gl_assert_remark_key', 'gl_bf_drain', 'gl_backfill_all'}


def objects(source):
    return [{'kind': match[1], 'name': match[2], 'definition': match[0][:-2]}
            for match in re.finditer(r'CREATE (FUNCTION|PROCEDURE|TRIGGER) (\w+)\b.*?END\$\$', source, re.S)
            if match[2] not in EXCLUDED]


def build(output, environment='uat'):
    if environment not in ['uat', 'prod']:
        raise ValueError('Unknown release environment')
    output.mkdir(mode=0o700, parents=True, exist_ok=True)
    suffix = '_uat' if environment == 'uat' else ''
    source_path = 'sql/general_ledger_triggers' + suffix + '.sql'
    source = (ROOT / source_path).read_text()
    baseline = subprocess.check_output(['git', 'show', 'HEAD:' + source_path], cwd=ROOT, text=True)
    full = objects(source)
    old_poster = next(obj for obj in objects(baseline) if obj['name'] == 'gl_post_item_history')
    poster = next(obj for obj in full if obj['name'] == 'gl_post_item_history')
    assert poster['definition'] == old_poster['definition'].replace('IFNULL(item.cost, 0)', 'IFNULL(item.weighted_cost, 0)')
    schema = (ROOT / 'sql/apv_entries/schema.sql').read_text() + '\n' + (ROOT / 'sql/apv_entries/cutover.sql').read_text()
    schema += '''
CREATE TABLE IF NOT EXISTS gl_settings (branch_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS non_cash_deposit LIKE {OAKRIDGE}.non_cash_deposit;
ALTER TABLE general_ledger ADD COLUMN IF NOT EXISTS source VARCHAR(250) NULL,
    ADD COLUMN IF NOT EXISTS source_id INT NULL;
-- Retain legacy columns and values; source-based inserts omit these old identifiers.
ALTER TABLE general_ledger ALTER COLUMN ledger_type SET DEFAULT '',
    ALTER COLUMN ledger_type_id SET DEFAULT 0,
    ALTER COLUMN ledger_type_item_id SET DEFAULT 0;
ALTER TABLE journal_entry ADD COLUMN IF NOT EXISTS source VARCHAR(250) NULL,
    ADD COLUMN IF NOT EXISTS source_id INT NULL,
    ADD COLUMN IF NOT EXISTS is_migrated TINYINT DEFAULT 0;
ALTER TABLE journal_entry MODIFY COLUMN remarks LONGTEXT DEFAULT NULL;
ALTER TABLE journal_entry_item MODIFY COLUMN remarks LONGTEXT DEFAULT NULL;
ALTER TABLE bank ADD COLUMN IF NOT EXISTS mop VARCHAR(250) NULL;
ALTER TABLE `transaction` ADD COLUMN IF NOT EXISTS is_worklist INT NULL DEFAULT 0;
ALTER TABLE cash_deposit ADD COLUMN IF NOT EXISTS deposit_date DATE NULL;
ALTER TABLE replenishment ADD COLUMN IF NOT EXISTS petty_cash_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_gl_source_id ON general_ledger(source,source_id,is_deleted);
CREATE INDEX IF NOT EXISTS idx_gl_date_account ON general_ledger(date,is_deleted,account_type_id);
CREATE INDEX IF NOT EXISTS idx_je_source_id ON journal_entry(source,source_id);
'''.replace('{OAKRIDGE}', 'mytemr_diagnostic' + suffix)
    package = {'environment': environment, 'clinic_objects': [poster], 'clinic_baseline': old_poster,
               'account_codes': sorted(set(re.findall(r"'([0-9]{3}-[0-9A-Z]{3,5})'", source))),
               'full_objects': full, 'excluded': sorted(EXCLUDED)}
    package['schema_before'] = {}
    for branch, label in [(5, 'warehouse'), (6, 'pharmacy')]:
        dump = (output.parent / (label + '_schema.sql')).read_text()
        package['schema_before'][str(branch)] = {
            match[1]: match[0] for match in re.finditer(r'CREATE TABLE `([^`]+)` \(.*?\) ENGINE=.*?;', dump, re.S)
            if re.search(r'\b' + re.escape(match[1]) + r'\b', source)}
    quantity_definitions = [re.search(r'`quantity` ([^\n]+)', package['schema_before'][str(branch)]['item_history'])[1].lower()
                            for branch in [5, 6]]
    if all(definition.startswith('int(') for definition in quantity_definitions):
        schema += '\nALTER TABLE item_history MODIFY COLUMN quantity DECIMAL(13,2) NOT NULL;\n'
    elif not all(definition.startswith('decimal(13,2)') for definition in quantity_definitions):
        raise ValueError('Review mixed or incompatible history quantity types before release')
    (output / 'prerequisites.sql').write_text(schema)
    package['existing_objects'] = {}
    if environment == 'prod':
        legacy = json.loads((output.parent / 'legacy_objects.json').read_text())
        package['existing_objects'] = {branch: {'gl_post_direct_payment': values['gl_post_direct_payment']}
                                       for branch, values in legacy.items()}
    canonical_dump = (output.parent / 'oakridge_schema.sql').read_text()
    package['non_cash_template'] = re.search(r'CREATE TABLE `non_cash_deposit` \(.*?\) ENGINE=.*?;', canonical_dump, re.S)[0]
    (output / 'manifest.json').write_text(json.dumps(package, indent=2))
    shutil.copyfile(ROOT / 'sql/gl_remark_names/deploy.py', output / 'gl_support.py')
    shutil.copyfile(Path(__file__).with_name('deploy.py'), output / 'deploy.py')
    checksums = {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in output.iterdir()
                 if path.is_file() and path.name != 'checksums.json'}
    (output / 'checksums.json').write_text(json.dumps(checksums, indent=2))
    for path in output.iterdir():
        path.chmod(0o600)
    print('Built ' + environment + '-only package; no database operations executed.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    parser.add_argument('--environment', choices=['uat', 'prod'], default='uat')
    args = parser.parse_args()
    build(args.output, args.environment)
