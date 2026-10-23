"""Read-only static GL routine/trigger dependency audit; emits no routine bodies or keys."""
import json,re,subprocess,sys
BASES=['mytemr_master','mytemr_diagnostic_mobile','mytemr_diagnostic','mytemr_diagnostic_mactan','mytemr_diagnostic_conso','mytemr_diagnostic_mantlewood','mytemr_oakridge_warehouse','mytemr_oakridge_pharmacy']
SCHEMAS=[b+s for s in ('','_uat') for b in BASES]
CLIENT=['mariadb','--batch','--skip-column-names','--default-character-set=utf8mb4']

def query(statement):return subprocess.check_output(CLIENT,input=statement,text=True).strip()
def audit():
 known={};tables={};bodies=[]
 for schema in SCHEMAS:
  rows=query("SELECT ROUTINE_TYPE,ROUTINE_NAME,HEX(ROUTINE_DEFINITION) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='"+schema+"'")
  known[schema]=set()
  for row in rows.splitlines():
   kind,name,raw=row.split('\t');known[schema].add(name.lower());bodies.append((schema,kind,name,bytes.fromhex(raw).decode()))
  tables[schema]=set(query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='"+schema+"'").splitlines())
  for row in query("SELECT TRIGGER_NAME,HEX(ACTION_STATEMENT) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='"+schema+"'").splitlines():
   name,raw=row.split('\t');bodies.append((schema,'TRIGGER',name,bytes.fromhex(raw).decode()))
 missing=[];qualified=0;ignored_tables=0;dynamic=[];optional=[];unrelated_dynamic=[]
 for schema,kind,name,body in bodies:
  # SQL literals and comments are not executable calls. Keep qualified identifiers.
  code=re.sub(r"'(?:\\.|''|[^'])*'|\"(?:\\.|\"\"|[^\"])*\"",' ',body)
  code=re.sub(r'--[^\n]*|\#[^\n]*|/\*[\s\S]*?\*/',' ',code).replace('`','')
  temporary=set(re.findall(r'CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(gl_\w+)',code,re.I))
  for target_schema,target in re.findall(r'(?:(\b\w+)\s*\.\s*)?\b(gl_\w+)\s*\(',code,re.I):
   target=target.lower();target_schema=target_schema or schema
   if target in tables.get(target_schema,set()) or target in temporary:
    ignored_tables+=1;continue
   if target_schema!=schema:qualified+=1
   if target not in known.get(target_schema,set()):
    guard=re.search(r"ELSEIF\s+EXISTS\s*\(SELECT\s+1\s+FROM\s+information_schema\.ROUTINES\s+WHERE\s+ROUTINE_SCHEMA=DATABASE\(\)\s+AND\s+ROUTINE_NAME='"+re.escape(target)+r"'\)\s+THEN\s+CALL\s+"+re.escape(target)+r"\s*\(",body,re.I)
    record={'schema':schema,'kind':kind,'object':name,'missing':target_schema+'.'+target}
    (optional if guard else missing).append(record)
  if re.search(r'\bPREPARE\b',code,re.I):
   (dynamic if name.startswith('gl_') or re.search(r'\bgl_\w+',body,re.I) else unrelated_dynamic).append({'schema':schema,'object':name})
 return {'schemas':len(SCHEMAS),'objects_scanned':len(bodies),'qualified_calls_checked':qualified,'table_references_excluded':ignored_tables,'dynamic_sql_requires_review':dynamic,'unrelated_dynamic_sql':unrelated_dynamic,'guarded_optional_calls':optional,'missing':missing}

if __name__=='__main__':
 result=audit();print(json.dumps(result,indent=2));sys.exit(bool(result['missing']))
