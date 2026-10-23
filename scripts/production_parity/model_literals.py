"""Final scoped removal of configured encryption literals; preserve exact runtime SQL."""
from release import *
import time


def apply(release):
    folder=release/'prod';patch=folder/'model_literals';patch.mkdir(exist_ok=True)
    before=json.loads((folder/'production.config.private.json').read_text());key=before['constants']['ENCRYPTION_KEY']
    for name in ['Wallet','Patient_emr','Booking_doctors_fee']:
        path=ROOT/'app/Models'/(name+'.php');text=path.read_text()
        if key not in text:raise RuntimeError('Expected frozen encryption literal missing: '+name)
        for variant in ['before','after']:(patch/variant).mkdir(exist_ok=True)
        (patch/'before'/(name+'.php')).write_text(text)
        updated=text.replace(key,'{$this->encryption_key}')
        (patch/'after'/(name+'.php')).write_text(updated)
        run([PHP,'-l',str(patch/'after'/(name+'.php'))],patch/(name+'.lint.log'))
    run([PHP,str(release/'model_query_fixture.php'),str(release)],patch/'query_equality.json')
    snippet_dir=Path('/home/mytemr/conf/web/accounting-back.myt-emr.com')
    snippets={p:p.read_bytes() for p in [snippet_dir/'nginx.conf_accounting_parity',snippet_dir/'nginx.ssl.conf_accounting_parity']}
    flag=snippet_dir/'accounting-parity-maintenance.flag'
    if flag.exists():raise RuntimeError('Existing maintenance hold')
    cron=Path('/var/spool/cron/crontabs/mytemr');cron_before=cron.read_text()
    cli={p:p.read_bytes() for p in Path('/etc/cron.d').glob('accounting-*-prod')}
    def crontab(text,label):
        path=patch/(label+'.crontab.private');path.write_text(text);run(['crontab','-u','mytemr',str(path)],patch/(label+'.log'))
    def atomic(path,content):
        stat=path.stat();tmp=path.with_name('.'+path.name+'.literal_patch');tmp.write_bytes(content);os.chown(tmp,stat.st_uid,stat.st_gid);tmp.chmod(stat.st_mode&0o777);tmp.replace(path)
    def nginx():run(['nginx','-t'],patch/'nginx_test.log');run(['systemctl','reload','nginx'],patch/'nginx_reload.log');time.sleep(0.5)
    def php():run(['systemctl','reload','php8.3-fpm'],patch/'php_reload.log')
    installed=False;success=False
    try:
        for p,content in snippets.items():atomic(p,content+('\nif (-f '+str(flag)+') { return 503; }\n').encode())
        flag.write_text('Scoped encryption reference update\n');flag.chmod(0o644);nginx()
        crontab('\n'.join('# PARITY_PAUSED '+line if 'production-accounting-cron.php' in line and not line.lstrip().startswith('#') else line for line in cron_before.split('\n')),'paused')
        for p,content in cli.items():atomic(p,'\n'.join('# PARITY_PAUSED '+line if str(ROOT) in line and not line.lstrip().startswith('#') else line for line in content.decode().split('\n')).encode())
        master=subprocess.check_output(['systemctl','show','php8.3-fpm','--property=MainPID','--value']).decode().strip()
        proc=Path('/proc')/master/'task'/master/'children';workers=proc.read_text().split() if proc.exists() else [];php();deadline=time.monotonic()+60
        while any(Path('/proc',pid).exists() for pid in workers):
            if time.monotonic()>deadline:raise RuntimeError('Worker drain timed out')
            time.sleep(0.5)
        installed=True
        for name in ['Wallet','Patient_emr','Booking_doctors_fee']:atomic(ROOT/'app/Models'/(name+'.php'),(patch/'after'/(name+'.php')).read_bytes())
        php();run([PHP,str(release/'verify.php'),str(ROOT),str(release),'live'],patch/'runtime.json')
        run([PHP,str(release/'postflight.php'),str(release)],patch/'configuration.json')
        manifest=json.loads((folder/'manifest.private.json').read_text());deployed=json.loads((folder/'deployed.json').read_text())
        for name in ['Wallet','Patient_emr','Booking_doctors_fee']:
            relative='app/Models/'+name+'.php';manifest[relative]={'before':digest(patch/'before'/(name+'.php')),'after':digest(ROOT/relative)}
            shutil.copy2(ROOT/relative,release/'stage'/relative)
            stat=(ROOT/relative).stat();deployed['files'][relative]={**manifest[relative],'owner':stat.st_uid,'group':stat.st_gid,'mode':oct(stat.st_mode&0o777)}
        save(folder/'manifest.private.json',manifest);save(folder/'manifest.json',{k:v for k,v in manifest.items() if k!='.env'});save(folder/'deployed.json',deployed)
        success=True
    finally:
        if installed and not success:
            for name in ['Wallet','Patient_emr','Booking_doctors_fee']:atomic(ROOT/'app/Models'/(name+'.php'),(patch/'before'/(name+'.php')).read_bytes())
            php()
        # Verify the coherent current release before restoring schedules/traffic.
        run([PHP,str(release/'verify.php'),str(ROOT),str(release),'live'],patch/'recovery_or_completion.json')
        for p,content in snippets.items():atomic(p,content)
        flag.unlink(missing_ok=True);nginx()
        crontab(cron_before,'restored')
        for p,content in cli.items():atomic(p,content)
    print(json.dumps({'ok':success,'models':3,'sql_equivalence':json.loads((patch/'query_equality.json').read_text())}),flush=True)


if __name__=='__main__':
    import sys
    apply(Path(sys.argv[1]))
