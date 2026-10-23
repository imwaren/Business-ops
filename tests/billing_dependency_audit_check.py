"""Dependency-auditor regressions without database or network access."""
import importlib.util
from pathlib import Path
path=Path(__file__).resolve().parents[1]/'sql/billing_runtime_repair/audit_dependencies.py'
spec=importlib.util.spec_from_file_location('billing_audit',path);audit=importlib.util.module_from_spec(spec);spec.loader.exec_module(audit)
audit.SCHEMAS=['master_fixture','clinic_fixture']
routines={'master_fixture':{
 'gl_caller':"BEGIN SELECT gl_missing(); INSERT INTO gl_bf_ids(id) VALUES(1); END",
 'gl_temp':"BEGIN CREATE TEMPORARY TABLE gl_bf_ids(id INT); INSERT INTO gl_bf_ids(id) VALUES(1); END",
 'gl_bridge':"BEGIN CALL clinic_fixture.gl_target(1); END",
 'soa_loader':"BEGIN SET @sql='SELECT 1'; PREPARE x FROM @sql; END"},'clinic_fixture':{'gl_target':'BEGIN DO 0; END'}}
triggers={'master_fixture':{'optional_hook':"BEGIN ELSEIF EXISTS (SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_optional') THEN CALL gl_optional(NEW.id); END IF; END"},'clinic_fixture':{}}

def query(statement):
 schema=next(s for s in audit.SCHEMAS if "='"+s+"'" in statement)
 if 'FROM information_schema.ROUTINES' in statement:return '\n'.join('PROCEDURE\t'+name+'\t'+body.encode().hex() for name,body in routines[schema].items())
 if 'FROM information_schema.TABLES' in statement:return 'gl_bf_ids' if schema=='master_fixture' else ''
 return '\n'.join(name+'\t'+body.encode().hex() for name,body in triggers[schema].items())
audit.query=query
result=audit.audit()
assert len(result['missing'])==1 and result['missing'][0]['missing']=='master_fixture.gl_missing'
assert len(result['guarded_optional_calls'])==1
assert result['qualified_calls_checked']==1
assert result['table_references_excluded']==3
assert not result['dynamic_sql_requires_review'] and len(result['unrelated_dynamic_sql'])==1
routines['master_fixture']['gl_missing']='BEGIN DO 0; END'
assert not audit.audit()['missing']
del routines['clinic_fixture']['gl_target']
assert audit.audit()['missing'][0]['missing']=='clinic_fixture.gl_target'
triggers['master_fixture']['optional_hook']='BEGIN CALL gl_optional(NEW.id); END'
assert any(r['missing']=='master_fixture.gl_optional' for r in audit.audit()['missing'])
print('PASS 8 dependency audit classification checks')
