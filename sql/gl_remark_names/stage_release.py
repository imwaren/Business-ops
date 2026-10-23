#!/usr/bin/env python3
"""Stage a private rendered release on VPS6; never print configuration secrets."""
import hashlib
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import sys

HOST_5 = 'root@vps5.znvme.mytdedi01.filipinowebservices.com'
HOST_6 = 'root@vps6.znvme.mytdedi01.filipinowebservices.com'
SSH = ['ssh','-i','/Users/myt_waren/.ssh/id_ed25519','-p','1969','-o','BatchMode=yes']


def main():
    os.umask(0o077)
    release = Path(sys.argv[1]).resolve()
    checkout = Path(__file__).resolve().parents[2]
    try:
        release.relative_to(checkout)
    except ValueError:
        pass
    else:
        raise RuntimeError('Rendered releases must stay outside the checkout.')
    release.chmod(0o700)
    package = release / 'package'
    package.chmod(0o700)
    manifest_path = package / 'manifest.json'
    manifest = json.loads(manifest_path.read_text())
    code = r'''
$root = $argv[1];
if (!defined('ROOTPATH')) { define('ROOTPATH', $root.'/'); }
if (!defined('APPPATH')) { define('APPPATH', $root.'/app/'); }
if (!defined('SYSTEMPATH')) { define('SYSTEMPATH', $root.'/vendor/codeigniter4/framework/system/'); }
require $root.'/vendor/codeigniter4/framework/system/Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv($root))->load();
require $root.'/app/Config/Constants.php';
$key = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : getenv('ENCRYPTION_KEY');
if ($key === false || $key === '') { $key = getenv('encryption.key'); }
if ($key === false || $key === '') { exit(1); }
fwrite(STDOUT, $key);
'''
    for environment in ['uat','prod']:
        host = 'zuat-accounting-back.myt-emr.com' if environment=='uat' else 'accounting-back.myt-emr.com'
        root = '/home/mytemr/web/'+host+'/public_html'
        result = subprocess.run(SSH+[HOST_5,shlex.join(['/usr/bin/php','-r',code,root])],capture_output=True)
        if result.returncode or not result.stdout:
            raise RuntimeError('Cannot read '+environment+' configured encryption key securely.')
        key_hex = result.stdout.hex()
        for kind in ['diagnostic','master']:
            name = 'install_'+environment+'_'+kind+'.sql'
            path = package/name
            path.chmod(0o600)
            source = path.read_text()
            if '__GL_ENCRYPTION_KEY_HEX__' not in source:
                raise RuntimeError('Release must be rebuilt from unrendered templates before staging.')
            source = source.replace('__GL_ENCRYPTION_KEY_HEX__',key_hex)
            path.write_text(source)
            manifest[name] = json.loads(json.dumps(manifest[name]).replace('__GL_ENCRYPTION_KEY_HEX__',key_hex))
            manifest[name]['sha256'] = hashlib.sha256(source.encode()).hexdigest()
    manifest_path.chmod(0o600)
    manifest_path.write_text(json.dumps(manifest,indent=2))
    shutil.copyfile(Path(__file__).with_name('deploy.py'),package/'deploy.py')
    (package/'deploy.py').chmod(0o600)
    remote = '/root/'+release.name
    subprocess.run(SSH+[HOST_6,'umask 077; mkdir -p '+shlex.quote(remote)+'; chmod 700 '+shlex.quote(remote)],check=True)
    subprocess.run(['scp','-O','-q','-i','/Users/myt_waren/.ssh/id_ed25519','-P','1969','-r',str(package),HOST_6+':'+remote+'/'],check=True)
    print('Private rendered package staged at '+remote+'/package. No SQL executed; no key values logged.')


if __name__=='__main__':
    try:
        main()
    except Exception:
        print('Private release staging failed; inspect configuration/runtime/access without displaying secrets.',file=sys.stderr)
        raise SystemExit(1)
