"""Exercise scoped definition restoration in disposable local schemas only."""
import hashlib
import importlib.util
from pathlib import Path
import re
import sys
import tempfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


deployment = load('remark_deployment', 'sql/gl_remark_names/deploy.py')
builder = load('remark_builder', 'sql/gl_remark_names/build_release.py')
preparation = load('remark_preparation', 'scripts/prepare_gl_remarks_sql.py')
deployment.CLIENT = ['/Applications/XAMPP/xamppfiles/bin/mysql','--no-defaults',
    '--socket=/private/tmp/company_cleanup_db/mysql.sock','-u','myt_waren',
    '--comments','--binary-mode','--default-character-set=utf8mb4','--batch','--skip-column-names']
checks = 0


def check(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1


def rewrite(source, environment):
    suffix = '_uat' if environment=='uat' else ''
    schemas = {'mytemr_master'+suffix: 'gl_names_'+environment+'_master'}
    schemas.update({schema+suffix:'gl_names_'+environment+'_branch_'+str(branch)
                    for branch,schema in deployment.BRANCHES.items()})
    for old,new in sorted(schemas.items(),key=lambda pair:len(pair[0]),reverse=True):
        source=re.sub(r'\b'+old+r'\b',new,source)
    return source


def history(database):
    return hashlib.sha256(deployment.query('SELECT * FROM general_ledger ORDER BY id; SELECT * FROM journal_entry ORDER BY id; SELECT * FROM journal_entry_item ORDER BY id;',database)).hexdigest()


with tempfile.TemporaryDirectory(prefix='remark_recovery_fixture_') as directory:
    deployment.ERROR_DIR=Path(directory)
    for environment in ['uat','prod']:
        for kind in ['diagnostic','master']:
            database='gl_names_'+environment+('_master' if kind=='master' else '_branch_2')
            source,details=builder.build(environment,kind)
            source=rewrite(preparation.render_sql(source,b'local-fixture-only-key'),environment)
            for obj in details['objects']:
                for field in ['definition','baseline_definition']:
                    if obj[field]:
                        obj[field]=rewrite(obj[field].replace(preparation.KEY_TOKEN,b'local-fixture-only-key'.hex()),environment)
            before=history(database)
            statements=['DELIMITER $$']
            for obj in reversed(details['objects']):
                statements.append('DROP '+obj['kind']+' IF EXISTS '+obj['name']+'$$')
            for obj in details['objects']:
                if obj['baseline_definition']:
                    statements.append(obj['baseline_definition'])
            statements.append('DELIMITER ;')
            deployment.query('\n'.join(statements),database)
            originals={'objects':{obj['name']: deployment.show(database,obj['kind'],obj['name'])
                                  if obj['baseline_definition'] else None for obj in details['objects']}}
            protected=deployment.inventory(database,details['objects'])
            deployment.query(source,database)
            restore=deployment.restore_sql(database,kind,originals,details)
            deployment.query(restore,database)
            for obj in details['objects']:
                old=originals['objects'][obj['name']]
                if old:
                    check(deployment.show(database,obj['kind'],obj['name'])==old,'Exact original definition/definer/mode/charset restored')
                else:
                    table,prefix=('TRIGGERS','TRIGGER') if obj['kind']=='TRIGGER' else ('ROUTINES','ROUTINE')
                    check(deployment.scalar('SELECT COUNT(*) FROM information_schema.'+table+' WHERE '+prefix+'_SCHEMA='+deployment.literal(database)+' AND '+prefix+'_NAME='+deployment.literal(obj['name']))=='0','New helper/hook removed during recovery')
            check(protected==deployment.inventory(database,details['objects']),'Unrelated definitions preserved during recovery')
            check(before==history(database),'Recovery does not change accounting history')
            check(deployment.scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('journal_entry','journal_entry_item') AND COLUMN_NAME='remarks' AND DATA_TYPE='longtext';",database)=='2','Safe additive LONGTEXT schema retained')
            deployment.query(source,database)
    print(f'PASS: {checks} isolated scoped-deployment recovery checks.')
