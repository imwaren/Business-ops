"""Build a secret-preserving, method-scoped release outside public_html."""
from release import *


def env_quote(value):
    value = str(value)
    if any(x in value for x in ['\n', '\r', '\0', '${']):
        raise RuntimeError('Environment value requires explicit safe serialization; no files installed')
    return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"


def build(release):
    folder=release/'prod';source=release/'uat_source';stage=release/'stage'
    if app_hashes(ROOT) != json.loads((folder/'production.before.json').read_text()):
        raise RuntimeError('Production source drift since backup')
    if stage.exists():
        stage.rename(release / ('stage_attempt_' + datetime.datetime.now().strftime('%H%M%S')))
    stage.mkdir(exist_ok=False)
    shutil.copytree(ROOT/'app',stage/'app')
    # Configuration compatibility files only; branch identities and route declarations stay production's.
    for path in (source/'app/Config').rglob('*.php'):
        name=str(path.relative_to(source))
        if name in ['app/Config/Database.php','app/Config/Branch.php','app/Config/Routes.php']:
            continue
        target=stage/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(path,target)
    for name in ['index.php','spark','.htaccess','app/Filters/SecurityHeaders.php','app/Libraries/CorsExceptionHandler.php','app/Libraries/PageInventory.php']:
        target=stage/name;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(source/name,target)
    core='app/Core/MYT_Controller.php'
    methods=['_remap','_api_log','_api_validation','_create_response','_decrypt_payload','_base_64_upload','_multipart_form_data_upload','_generate_token',
             '_cron_authorized','_controller_short_name','_is_admin_role','_require_admin','_require_self_or_admin','_permission_allowed','_requester_branch_ids']
    text=replace_functions(ROOT/core,source/core,methods,release,True)
    anchor='    protected $public_page'
    offset=text.index(anchor)
    text=text[:offset]+"    protected $cron_page = false;\n    protected $requester_user;\n"+text[offset:]
    (stage/core).write_text(text)
    common='app/Common.php'
    (stage/common).write_text(replace_functions(ROOT/common,source/common,['api_key_is_valid'],release))
    selections={'Login':['_attempt_login'],'Roles':['create','create_multiple','update','delete'],
                'Permissions':['insert_permissions'],'Pages':['insert_pages'],
                'Files':['get_file','fetchFileContent'],'Prints':['printJob']}
    for controller, methods in selections.items():
        name='app/Controllers/'+controller+'.php'
        (stage/name).write_text(replace_functions(ROOT/name,source/name,methods,release))
    # Portal password/response hardening, without adding UAT-only recovery endpoints.
    name='app/Controllers/User_portals.php'
    text=replace_functions(ROOT/name,source/name,['get','login'],release)
    text=re.sub(r'password_hash\(([^\n]+?),\s*PASSWORD_(?:BCRYPT|DEFAULT)\)',r'app_password_hash(\1)',text)
    (stage/name).write_text(text)
    # Keep current Users endpoints and mutations; the selected UAT password validation lives in Config.
    # Preserve production email content/field choices while applying UAT's output escaping.
    for template in ['forgot_password','assessment_result','enrollment_payment_failed','parent_sign_up']:
        path=stage/'app/Views/emails'/(template+'.php')
        text=path.read_text()
        text=re.sub(r'<\?=(.*?)\?>',lambda match:'<?= '+match.group(1).strip()+' ?>' if match.group(1).strip().startswith('esc(') else "<?= esc("+match.group(1).strip()+", 'html') ?>",text,flags=re.S)
        path.write_text(text)
    database='app/Config/Database.php'
    text=(ROOT/database).read_text();bounds=functions(ROOT/database,release)['__construct']
    text=text[:bounds['start']]+(release/'database_constructor.fragment').read_text().rstrip()+text[bounds['end']:]
    # Remove credential literals/defaults. All MySQL connections below are exported individually.
    for field in ['DSN','hostname','username','password','database']:
        text=re.sub(r"(?m)^(\s*'"+field+r"'\s*=>\s*)[^\n,]+,",r"\1'',",text)
    (stage/database).write_text(text)
    before=json.loads((folder/'production.config.private.json').read_text())
    uat=json.loads((folder/'uat.config.private.json').read_text())
    constants=before['constants']
    values=dict(before['env'])
    # Export exact production values for settings consumed by the new Constants file.
    required=set(re.findall(r"myt_env\(\s*['\"]([\w.-]+)",(stage/'app/Config/Constants.php').read_text()))
    for key in required:
        if key in constants and isinstance(constants[key],(str,int,float,bool)):
            values[key]=constants[key]
    old_defines=set(re.findall(r"define\(\s*['\"]([A-Z_][A-Z_0-9]*)",(ROOT/'app/Config/Constants.php').read_text()))
    new_defines=set(re.findall(r"define\(\s*['\"]([A-Z_][A-Z_0-9]*)",(stage/'app/Config/Constants.php').read_text()))
    for key in sorted(old_defines-new_defines):
        value=constants[key]
        if not isinstance(value,(str,int,float,bool)):
            raise RuntimeError('Review non-scalar production constant '+key)
        values[key]=value
        cast='string' if isinstance(value,str) else ('bool' if isinstance(value,bool) else 'int' if isinstance(value,int) else 'float')
        with (stage/'app/Config/Constants.php').open('a') as out:
            out.write("\ndefined('"+key+"') || define('"+key+"', ("+cast+") myt_env('"+key+"', ''));\n")
    values.update({'CI_ENVIRONMENT':'production','app.baseURL':'https://accounting-back.myt-emr.com/',
        'app.forceGlobalSecureRequests':'true','cookie.secure':'true','app.appTimezone':'Asia/Manila',
        'ROLE_ADMIN':uat['constants'].get('ROLE_ADMIN',4),'ROLE_IT':uat['constants'].get('ROLE_IT',17),
        'ERROR_RESPONSE_DETAILS':'true','CRON_SECRET':secrets.token_hex(32),
        'CORS_ALLOWED_ORIGINS':','.join(uat['origins']),
        'FRONT_END_URL':constants['FRONT_END_URL'],
        'ENCRYPTION_KEY':constants['ENCRYPTION_KEY'],'encryption.key':before['encryption']['key'],
        'API_KEY':constants['API_KEY'],'API_KEYS':constants['API_KEY']})
    if not uat['origins']:
        raise RuntimeError('UAT effective CORS allowlist unavailable')
    # Preserve CSR key selection; avoid an empty list overriding a configured single key.
    if not values.get('CSR_API_KEYS') and constants.get('CSR_API_KEY'):
        values['CSR_API_KEYS']=constants['CSR_API_KEY']
    group_names={'default':'MASTER_DB','master':'MASTER_DB','mobile':'MOBILE_DB','oakridge':'OAKRIDGE_DB','mactan':'MAC_DB',
        'consolacion':'CONSO_DB','mantlewood':'MANTLEWOOD_DB','pharmacy':'PHARMACY_DB','warehouse':'WAREHOUSE_DB','report':'REPORT_DB',
        'oakridge_emr':'OAKRIDGE_EMR_DB','mactan_emr':'MAC_EMR_DB','consolacion_emr':'CONSO_EMR_DB','mantlewood_emr':'MANTLEWOOD_EMR_DB','mobile_emr':'MOBILE_EMR_DB'}
    for group,settings in before['groups'].items():
        if group not in group_names or settings['DBDriver']!='MySQLi' or '_uat' in settings['database'].lower():
            raise RuntimeError('Unexpected production database group: '+group)
        for field in ['hostname','username','password','database','port']:
            values['database.'+group+'.'+field]=settings[field]
        values[group_names[group]]=settings['database']
    # Capture only literal assignments from the existing encrypted-payload helper.
    spans=functions(ROOT/core,release)['_decrypt_payload'];body=(ROOT/core).read_text()[spans['start']:spans['end']]
    fragment=folder/'payload.private.php';fragment.write_text('<?php\nclass PrivatePayload {\n'+body+'\n}\n')
    run([PHP,str(release/'php_inventory.php'),'literals',str(fragment),str(folder/'payload.private.json')],folder/'payload_export.log')
    payload=json.loads((folder/'payload.private.json').read_text())
    for name,key in [('key','PAYLOAD_AES_KEY'),('iv','PAYLOAD_AES_IV')]:
        if name in payload:values[key]=payload[name]
    # Config app/session values migrate with the compatible UAT configuration structure.
    for name in ['sessionDriver','sessionCookieName','sessionExpiration','sessionSavePath','sessionMatchIP','sessionTimeToUpdate','sessionRegenerateDestroy']:
        if name in before['app']:
            target={'sessionDriver':'driver','sessionCookieName':'cookieName','sessionExpiration':'expiration','sessionSavePath':'savePath','sessionMatchIP':'matchIP','sessionTimeToUpdate':'timeToUpdate','sessionRegenerateDestroy':'regenerateDestroy'}[name]
            value=before['app'][name]
            # Stage should use its own writable directory; live retains the same path.
            if name!='sessionSavePath':values['session.'+target]='true' if value is True else 'false' if value is False else value
    # Keep safe production defaults even when an optional URL setting is absent.
    app_path=stage/'app/Config/App.php'
    app_source=app_path.read_text()
    app_source=re.sub(r"(public\s+\$baseURL\s*=\s*)['\"][^'\"]*['\"]",r"\1'https://accounting-back.myt-emr.com/'",app_source)
    app_path.write_text(app_source)
    constant_path=stage/'app/Config/Constants.php'
    constant_source=constant_path.read_text()
    for key in set(group_names.values()) | {'FRONT_END_URL','ACCOUNTING_FRONT_END_URL'}:
        if key in values and isinstance(values[key],str):
            constant_source=re.sub(r"myt_env\('"+re.escape(key)+r"',\s*'[^']*'\)",lambda m:"myt_env('"+key+"', '"+values[key]+"')",constant_source)
    constant_path.write_text(constant_source)
    (stage/'.env').write_text('# Production configuration; private, do not commit.\n'+''.join(key+' = '+env_quote(value)+'\n' for key,value in sorted(values.items()) if isinstance(value,(str,int,float,bool))))
    (stage/'.env').chmod(0o600)
    save(folder/'expected_env.private.json',values)
    # Locked, explicit dependencies: include the previously unmanaged PHPMailer installation.
    composer=json.loads((ROOT/'composer.json').read_text())
    composer['require'].update({'php':'^8.1','codeigniter4/framework':'4.7.4','phpmailer/phpmailer':'7.0.2'})
    composer.setdefault('config',{})['platform']={'php':subprocess.check_output([PHP,'-r','echo PHP_VERSION;']).decode()}
    composer['config']['allow-plugins']=False
    save(stage/'composer.json',composer)
    shutil.copy2(ROOT/'composer.lock',stage/'composer.lock')
    composer_phar=release/'composer.phar'
    if not composer_phar.exists():
        urllib.request.urlretrieve('https://getcomposer.org/composer-stable.phar',composer_phar)
    expected=urllib.request.urlopen('https://getcomposer.org/download/latest-stable/composer.phar.sha256sum',timeout=30).read().decode().split()[0]
    if digest(composer_phar)!=expected:raise RuntimeError('Composer checksum mismatch')
    (folder/'composer_verified.sha256').write_text(expected+'\n')
    run([PHP,str(composer_phar),'update','codeigniter4/framework','phpmailer/phpmailer','--with-dependencies','--no-dev','--no-interaction','--no-plugins','--no-scripts','--prefer-dist','--optimize-autoloader','--working-dir='+str(stage)],folder/'composer_build.private.log')
    run([PHP,str(composer_phar),'check-platform-reqs','--no-dev','--working-dir='+str(stage)],folder/'platform.log')
    for name in ['logs','cache','session']:(stage/'writable'/name).mkdir(parents=True,exist_ok=True)
    manifest={}
    for path in list((stage/'app').rglob('*'))+[stage/name for name in ['index.php','spark','.htaccess','composer.json','composer.lock','.env']]:
        if path.is_file():
            name=str(path.relative_to(stage))
            if digest(path)!=digest(ROOT/name):
                manifest[name]={'before':digest(ROOT/name),'after':digest(path)}
                if name.endswith('.php') or name=='spark':run([PHP,'-l',str(path)],folder/'lint.log')
    save(folder/'manifest.private.json',manifest)
    save(folder/'manifest.json',{key:value for key,value in manifest.items() if key!='.env'})
    print(json.dumps({'ok':True,'phase':'build','files':list(manifest),'groups':len(before['groups']),'cors_origins':len(uat['origins'])}),flush=True)
