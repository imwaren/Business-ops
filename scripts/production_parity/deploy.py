#!/usr/bin/env python3
"""Deploy the verified production parity release with a production-only hold and recovery."""
from release import *
import shlex
import time
import urllib.error


def execute(release):
    folder=release/'prod';stage=release/'stage'
    manifest=json.loads((folder/'manifest.private.json').read_text())
    old_hashes=json.loads((folder/'production.before.json').read_text())
    uat_hashes=json.loads((folder/'uat.before.json').read_text())
    if app_hashes(ROOT)!=old_hashes or app_hashes(UAT)!=uat_hashes:
        raise RuntimeError('Source drift; deployment stopped before maintenance')
    if any(digest(stage/name)!=record['after'] for name,record in manifest.items()):
        raise RuntimeError('Staged release drift')
    def command(args,name):return run(args,folder/(name+'.private.log'))
    # All commands below are either private synthetic checks or dependency metadata checks.
    command([PHP,str(release/'verify.php'),str(stage),str(release),'live'],'preflight_runtime')
    command([PHP,str(release/'security_fixture.php'),str(stage)],'preflight_security')
    command([PHP,str(stage/'spark'),'routes'],'routes')
    command([PHP,str(release/'composer.phar'),'validate','--no-check-publish','--working-dir='+str(stage)],'composer_validate')
    audit=subprocess.run([PHP,str(release/'composer.phar'),'audit','--locked','--no-dev','--format=json','--working-dir='+str(stage)],capture_output=True,text=True)
    (folder/'audit.private.json').write_text(audit.stdout)
    (folder/'audit.stderr.private.log').write_text(audit.stderr)
    if audit.returncode:raise RuntimeError('Dependency audit failed; no production changes installed')
    vendor_before=tree_digest(ROOT/'vendor');vendor_release=tree_digest(stage/'vendor')
    save(folder/'vendor_hashes.json',{'before':vendor_before,'release':vendor_release})
    config_dir=Path('/home/mytemr/conf/web/accounting-back.myt-emr.com')
    snippets=[config_dir/'nginx.conf_accounting_parity',config_dir/'nginx.ssl.conf_accounting_parity']
    if any(path.exists() for path in snippets):raise RuntimeError('Unexpected existing parity nginx configuration')
    private_helper=config_dir/'production-accounting-cron.php'
    if private_helper.exists():raise RuntimeError('Unexpected existing cron helper')
    flag=config_dir/'accounting-parity-maintenance.flag'
    if flag.exists():raise RuntimeError('Production already has a parity maintenance hold')
    probe_secret=secrets.token_hex(32)
    (folder/'probe_secret.private').write_text(probe_secret)
    extra='\n# Accounting parity security controls; sensitive paths must be denied before static handling.\n'
    extra+='if ($uri ~* "^/(?:\\.env[^/]*|composer\\.(?:json|lock)|spark|phpunit[^/]*|phpstan[^/]*|psalm[^/]*)(?:/|$)") { return 404; }\n'
    extra+='if ($uri ~* "^/(?:vendor|tests|scripts|sql|docs|build|\\.git)(?:/|$)") { return 404; }\n'
    hold='\nset $accounting_parity_hold 0;\nif (-f '+str(flag)+') { set $accounting_parity_hold 1; }\n'
    hold+='if ($http_x_parity_probe = "'+probe_secret+'") { set $accounting_parity_hold 0; }\nif ($accounting_parity_hold = 1) { return 503; }\n'
    def reload_nginx():
        command(['nginx','-t'],'nginx_test');command(['systemctl','reload','nginx'],'nginx_reload')
        time.sleep(0.5)
    def reload_php():command(['systemctl','reload','php8.3-fpm'],'php_reload')
    def request(path='/items/get_items_list',method='OPTIONS',origin='https://accounting-front.myt-emr.com',bypass=False):
        headers={'Origin':origin,'Access-Control-Request-Method':'GET','Access-Control-Request-Headers':'api-key,origin-api-key'}
        if bypass:headers['X-Parity-Probe']=probe_secret
        req=urllib.request.Request('https://accounting-back.myt-emr.com'+path,method=method,headers=headers)
        try:
            with urllib.request.urlopen(req,timeout=30) as response:
                return {'status':response.status,'headers':{name.lower():response.headers.get_all(name) for name in response.headers if name.lower().startswith(('access-control-','x-frame','x-content','strict-transport','content-security','referrer-policy','permissions-policy'))}}
        except urllib.error.HTTPError as error:return {'status':error.code,'headers':{}}
    schedule_path=Path('/var/spool/cron/crontabs/mytemr')
    hestia_path=Path('/usr/local/hestia/data/users/mytemr/cron.conf')
    schedules={path:path.read_text() for path in [schedule_path,hestia_path]}
    pattern=re.compile(r'wget\s+-q\s+-O\s+-\s+(https://accounting-back\.myt-emr\.com/cron/[^\s\x22\x27]+)')
    updated={}
    for path,text in schedules.items():
        value,count=pattern.subn(lambda m:PHP+' '+str(private_helper)+' '+m.group(1),text)
        if count!=11:raise RuntimeError('Unexpected production HTTP cron count')
        updated[path]=value
    cli_schedules={path:path.read_bytes() for path in Path('/etc/cron.d').glob('accounting-*-prod')}
    for path,content in cli_schedules.items():
        backup=folder/'before'/str(path).lstrip('/');backup.parent.mkdir(parents=True,exist_ok=True);backup.write_bytes(content)
    services={name:subprocess.check_output(['systemctl','is-active',name]).decode().strip() for name in ['nginx','apache2','php8.3-fpm','php8.2-fpm','cron']}
    save(folder/'services.before.json',services)
    incoming=ROOT.parent/('private_parity_'+release.name.removeprefix('prod_uat_parity_'))
    incoming.mkdir(mode=0o700,exist_ok=True)
    if (incoming/'vendor.before').exists():raise RuntimeError('Review existing retained vendor before retry')
    if not (incoming/'vendor.new').exists():shutil.copytree(stage/'vendor',incoming/'vendor.new')
    if tree_digest(incoming/'vendor.new')!=vendor_release:raise RuntimeError('Incoming vendor checksum mismatch')
    subprocess.run(['chown','-R','mytemr:mytemr',str(incoming/'vendor.new')],check=True)
    baseline_probe=subprocess.run([PHP,str(release/'http_probe.php'),'prod'],capture_output=True,text=True)
    (folder/'baseline_http.private.json').write_text(baseline_probe.stdout)
    if baseline_probe.returncode or not json.loads(baseline_probe.stdout).get('ok'):
        raise RuntimeError('Existing production read-only GET baseline failed')
    before_requests={'preflight':request()}
    save(folder/'http.before.json',before_requests)
    baseline_files={name:(ROOT/name).read_bytes() if (ROOT/name).exists() else None for name in manifest}
    stats={name:((ROOT/name).stat().st_uid,(ROOT/name).stat().st_gid,(ROOT/name).stat().st_mode&0o777) if (ROOT/name).exists() else (1004,1004,0o644) for name in manifest}
    swapped_vendor=False;installed=False;hold_active=False;cron_paused=False
    def atomic(path,content,uid=1004,gid=1004,mode=0o644):
        path.parent.mkdir(parents=True,exist_ok=True)
        staged=path.with_name('.'+path.name+'.parity')
        staged.write_bytes(content);os.chown(staged,uid,gid);staged.chmod(mode);staged.replace(path)
    def install_crontab(text,label):
        file=folder/(label+'.crontab.private');file.write_text(text)
        command(['crontab','-u','mytemr',str(file)],label)
    def install_hestia(text):
        stat=hestia_path.stat();atomic(hestia_path,text.encode(),stat.st_uid,stat.st_gid,stat.st_mode&0o777)
    def restore_cli():
        for path,content in cli_schedules.items():
            stat=path.stat();atomic(path,content,stat.st_uid,stat.st_gid,stat.st_mode&0o777)
    def verify_services():return all(subprocess.check_output(['systemctl','is-active',name]).decode().strip()==state for name,state in services.items())
    print('Production staged checks/audit passed; beginning protected cutover.',flush=True)
    try:
        for path in snippets:atomic(path,(extra+hold).encode(),0,0,0o644)
        reload_nginx()
        # Denials must be effective before populating the live environment file.
        paths=['/.env','/composer.json','/composer.lock','/vendor/composer/installed.json','/tests/','/scripts/']
        deadline=time.monotonic()+20
        while True:
            denied={path:request(path,'HEAD')['status'] for path in paths}
            save(folder/'sensitive_paths.private.json',denied)
            if all(status in [403,404] for status in denied.values()):break
            if time.monotonic()>deadline:raise RuntimeError('Sensitive paths remain exposed')
            time.sleep(0.5)
        flag.write_text('Production parity maintenance\n');flag.chmod(0o644);hold_active=True
        if request()['status']!=503:raise RuntimeError('Production maintenance hold not effective')
        paused='\n'.join('# PARITY_PAUSED '+line if 'https://accounting-back.myt-emr.com/cron/' in line and not line.lstrip().startswith('#') else line for line in schedules[schedule_path].split('\n'))
        install_crontab(paused,'paused');cron_paused=True
        for path,content in cli_schedules.items():
            text='\n'.join('# PARITY_PAUSED '+line if str(ROOT) in line and not line.lstrip().startswith('#') else line for line in content.decode().split('\n'))
            stat=path.stat();atomic(path,text.encode(),stat.st_uid,stat.st_gid,stat.st_mode&0o777)
        # Drain the prior PHP 8.3 worker generation after new HTTP work is blocked.
        master=subprocess.check_output(['systemctl','show','php8.3-fpm','--property=MainPID','--value']).decode().strip()
        children=Path('/proc')/master/'task'/master/'children'
        old_workers=children.read_text().split() if children.exists() else []
        reload_php()
        deadline=time.monotonic()+60
        while any(Path('/proc',pid).exists() for pid in old_workers):
            if time.monotonic()>deadline:raise RuntimeError('PHP worker drain timed out')
            time.sleep(0.5)
        # Wait for existing production CLI application jobs rather than interrupting them.
        deadline=time.monotonic()+60
        while True:
            active=[]
            for proc in Path('/proc').glob('[0-9]*/cmdline'):
                try:args=proc.read_bytes().replace(b'\0',b' ').decode(errors='replace')
                except (FileNotFoundError,PermissionError,ProcessLookupError):continue
                if str(ROOT)+'/spark' in args and 'php' in args:active.append(proc.parent.name)
            if not active:break
            if time.monotonic()>deadline:raise RuntimeError('Existing production CLI jobs have not drained')
            time.sleep(1)
        if app_hashes(ROOT)!=old_hashes or tree_digest(ROOT/'vendor')!=vendor_before:raise RuntimeError('Application/vendor drift immediately before installation')
        (ROOT/'vendor').rename(incoming/'vendor.before');(incoming/'vendor.new').rename(ROOT/'vendor');swapped_vendor=True
        # .env is installed before new Constants; maintenance prevents mixed generations.
        names=['.env']+[name for name in manifest if name!='.env']
        installed=True
        for name in names:
            uid,gid,mode=stats[name]
            atomic(ROOT/name,(stage/name).read_bytes(),uid,gid,0o600 if name=='.env' else mode & ~0o022)
        atomic(private_helper,(release/'cron_request.php').read_bytes(),1004,1004,0o700)
        command(['apache2ctl','configtest'],'apache_test');reload_php()
        command([PHP,str(release/'verify.php'),str(ROOT),str(release),'live'],'installed_runtime')
        command([PHP,str(release/'security_fixture.php'),str(ROOT)],'installed_security')
        # Test rendering privately, never send synthetic exceptions through the live logger.
        command([PHP,str(release/'error_probe.php')],'installed_error')
        preflight=request(bypass=True)
        if preflight['status']!=204:raise RuntimeError('Installed preflight failed')
        allowed={token.strip().lower() for line in preflight['headers'].get('access-control-allow-headers',[]) for token in line.split(',')}
        if not {'api-key','origin-api-key'}<=allowed:raise RuntimeError('Required CORS headers missing')
        # Private header bypasses maintenance only, never application authentication.
        environment=os.environ.copy();environment['PARITY_PROBE_HEADER']=probe_secret
        result=subprocess.run([PHP,str(release/'http_probe.php'),'prod'],capture_output=True,text=True,env=environment)
        (folder/'installed_http.private.json').write_text(result.stdout);(folder/'installed_http.stderr.private.log').write_text(result.stderr)
        if result.returncode or not json.loads(result.stdout).get('ok'):raise RuntimeError('Installed read-only GET checks failed')
        old_cases=json.loads(baseline_probe.stdout)['cases'];new_cases=json.loads(result.stdout)['cases']
        if any(old_cases[name].get(key)!=case.get(key) for name,case in new_cases.items() for key in ['response_keys','row_keys']):
            raise RuntimeError('Existing API response structure changed')
        if request(origin='https://not-allowed-parity.invalid',bypass=True)['headers'].get('access-control-allow-origin'):raise RuntimeError('Unknown origin allowed')
        if any(digest(ROOT/name)!=record['after'] for name,record in manifest.items()) or tree_digest(ROOT/'vendor')!=vendor_release:raise RuntimeError('Installed checksum mismatch')
        if app_hashes(UAT)!=uat_hashes:raise RuntimeError('Unexpected UAT source/configuration change')
        after_hashes=app_hashes(ROOT)
        if any(after_hashes.get(name)!=value for name,value in old_hashes.items() if name not in manifest):raise RuntimeError('Unrelated production application drift')
        # Activate header-authenticated HTTP schedules; unrelated UAT lines remain identical.
        install_hestia(updated[hestia_path]);install_crontab(updated[schedule_path],'final');restore_cli();cron_paused=False
        flag.unlink();hold_active=False
        for path in snippets:atomic(path,extra.encode(),0,0,0o644)
        reload_nginx()
        deadline=time.monotonic()+30
        transitions=[]
        while True:
            final=request();healthy=verify_services()
            transitions.append({'status':final['status'],'services_healthy':healthy})
            save(folder/'public_readiness_transitions.json',transitions)
            if final['status']==204 and healthy:break
            if time.monotonic()>deadline:raise RuntimeError('Final public readiness/service checks failed')
            time.sleep(0.5)
        if any(request(path,'HEAD')['status'] not in [403,404] for path in paths):raise RuntimeError('Final sensitive path check failed')
        records={name:{**record,'owner':stats[name][0],'group':stats[name][1],'mode':oct(0o600 if name=='.env' else stats[name][2]&~0o022)} for name,record in manifest.items() if name!='.env'}
        save(folder/'deployed.json',{'ok':True,'environment':'prod','files':records,'vendor_path_backup':str(incoming/'vendor.before'),
            'preflight':final,'http':json.loads(result.stdout),'uat_unchanged':True,'unrelated_production_files_unchanged':True,
            'maintenance_restored':not flag.exists(),'scheduled_http_jobs':11,'cli_schedules_restored':True,'services_restored':verify_services(),
            'business_writes':False,'emails_sent':0,'schema_changes':False})
        print('Production parity deployed; protected checks, public readiness, schedules and UAT isolation passed.',flush=True)
    except Exception:
        # Preserve the hold until the exact previous coherent runtime is restored.
        flag.write_text('Recovery verification in progress\n');flag.chmod(0o644)
        if installed:
            for name,content in baseline_files.items():
                if content is None:(ROOT/name).unlink(missing_ok=True)
                else:
                    uid,gid,mode=stats[name];atomic(ROOT/name,content,uid,gid,mode)
        if swapped_vendor:
            (ROOT/'vendor').rename(incoming/'vendor.failed');(incoming/'vendor.before').rename(ROOT/'vendor')
        private_helper.unlink(missing_ok=True)
        install_hestia(schedules[hestia_path]);install_crontab(schedules[schedule_path],'recovered');restore_cli()
        reload_php();command(['apache2ctl','configtest'],'recovery_apache')
        if app_hashes(ROOT)!=old_hashes or app_hashes(UAT)!=uat_hashes or tree_digest(ROOT/'vendor')!=vendor_before:
            raise RuntimeError('Recovery checksum verification failed; maintenance remains active')
        for path in snippets:path.unlink(missing_ok=True)
        flag.unlink(missing_ok=True);reload_nginx()
        if request()['status']!=before_requests['preflight']['status'] or not verify_services():
            flag.write_text('Recovery readiness requires operator attention\n');flag.chmod(0o644)
            for path in snippets:atomic(path,(extra+hold).encode(),0,0,0o644)
            reload_nginx()
            raise RuntimeError('Recovery readiness verification failed; maintenance hold retained')
        save(folder/'recovered.json',{'ok':True,'application_restored':True,'vendor_restored':True,'configuration_restored':True,'schedules_restored':True})
        print('Exact prior application/dependencies/configuration and schedules restored after failed verification.',flush=True)
        raise


if __name__=='__main__':
    import sys
    path=Path(sys.argv[1]).resolve()
    if not str(path).startswith('/root/prod_uat_parity_'):raise RuntimeError('Private production release required')
    execute(path)
