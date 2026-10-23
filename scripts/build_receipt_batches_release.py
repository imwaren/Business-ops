#!/usr/bin/env python3
"""Build private receipt-batch packages from the reviewed VPS5 baselines."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile

os.umask(0o077)
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('output', type=Path)
args = parser.parse_args()
repository = Path(__file__).resolve().parents[1]
release = args.output.resolve()
release.mkdir(parents=True, exist_ok=False)
release.chmod(0o700)
# Dependencies precede controllers in the installed manifest.
files = ['app/Models/Branch_item.php', 'app/Models/Branch_item_transfer.php',
         'app/Models/Branch_item_transfer_item.php', 'app/Models/Item_cost.php',
         'app/Models/Receipt_note_item.php', 'app/Models/Receipt_note_item_expiry_date.php',
         'app/Controllers/Traits/Weighted_cost.php', 'app/Controllers/Receipt_notes.php', 'app/Controllers/Items.php']
baseline_overrides = {
    'uat': {'app/Controllers/Receipt_notes.php': '8c6c0e0088a46a6a582be80a4922f2605552a3211fed698ec96c9f1dd8c8b82d'},
    'prod': {'app/Controllers/Receipt_notes.php': '33e08bc61f334b5126d679adefa21bf6d5c36056c147d65a3186851e76d85e8b',
             'app/Models/Branch_item_transfer.php': '55c6c7c9d42845663dea6570735ef043d88c4a575d4ac73c8ac3231c671ceb4e',
             'app/Models/Branch_item_transfer_item.php': '1ff9face47b4f58d1dd17774fcb748c3c2368c18140930448aa6fdc2dd543bb6',
             'app/Models/Receipt_note_item.php': '2d2b9865962dcb498624238ccef7226c6db9adbb6f37d3afda73fb632277db55',
             'app/Models/Receipt_note_item_expiry_date.php': '7233f85f50fcda5daf2917e8b9739a60ed6bfe6e780196950efd61652ff9f510'}}
ssh = ['ssh', '-i', '/Users/myt_waren/.ssh/id_ed25519', '-p', '1969', '-o', 'BatchMode=yes',
       '-o', 'ConnectTimeout=15', 'root@vps5.znvme.mytdedi01.filipinowebservices.com']
for environment in ['uat', 'prod']:
    domain = ('zuat-' if environment == 'uat' else '') + 'accounting-back.myt-emr.com'
    archive = subprocess.check_output(ssh + ['tar -C /home/mytemr/web/' + domain + '/public_html -cf - ' + ' '.join(files)])
    folder = release / environment
    manifest = {'environment': environment, 'files': {}, 'environment_preservation':
                ['disabled receipt create attachments'] if environment == 'uat' else ['existing model pagination', 'receipt attachments enabled']}
    with tarfile.open(fileobj=io.BytesIO(archive)) as source:
        for name in files:
            deployed = source.extractfile(name).read()
            original = subprocess.check_output(['git', 'show', 'HEAD:' + name], cwd=repository)
            expected = baseline_overrides[environment].get(name, hashlib.sha256(original).hexdigest())
            if hashlib.sha256(deployed).hexdigest() != expected:
                raise RuntimeError('Unreviewed deployed drift: ' + environment + '/' + name)
            text = (repository / name).read_text()
            if name == 'app/Controllers/Receipt_notes.php':
                if environment == 'uat':
                    start = text.index('        } elseif (!$this->_attempt_create_attachments($receipt_note_id)) { //commented out for UAT only')
                    end = text.index('        } else {', start)
                    block = text[start:end]
                    text = text[:start] + ''.join('        // ' + line[8:] if line.strip() else line for line in block.splitlines(keepends=True)) + text[end:]
                    text = text.rstrip() + '\n\n'
                else:
                    text = text.replace('} elseif (!$this->_attempt_create_attachments($receipt_note_id)) { //commented out for UAT only', '} elseif (!$this->_attempt_create_attachments($receipt_note_id)) {')
            if environment == 'prod' and name in ['app/Models/Branch_item_transfer.php', 'app/Models/Branch_item_transfer_item.php',
                                                   'app/Models/Receipt_note_item.php', 'app/Models/Receipt_note_item_expiry_date.php']:
                old = '        $sql .= sql_limit_clause($page, $data_size);'
                retained = '        if ($page && $data_size) {\n            $page -= 1;\n            $offset = $page * $data_size;\n            $sql .= " LIMIT $offset, $data_size";\n        }'
                if old not in text:
                    raise RuntimeError('Pagination overlay no longer matches: ' + name)
                text = text.replace(old, retained, 1)
            target = folder / 'stage' / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(text)
            before = folder / 'baseline' / name
            before.parent.mkdir(parents=True, exist_ok=True)
            before.write_bytes(deployed)
            subprocess.run(['php', '-l', str(target)], check=True, capture_output=True)
            manifest['files'][name] = {'before': expected, 'release': hashlib.sha256(target.read_bytes()).hexdigest(),
                                       'local': hashlib.sha256((repository / name).read_bytes()).hexdigest()}
    manifest['sql_sha256'] = hashlib.sha256((repository / 'sql/receipt_batches/install.sql').read_bytes()).hexdigest()
    (folder / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
for source, name in [('scripts/deploy_receipt_batches.py', 'deploy.py'),
                     ('scripts/receipt_batches_deployment_probe.php', 'probe.php'),
                     ('sql/receipt_batches/install.sql', 'install.sql')]:
    shutil.copyfile(repository / source, release / name)
(release / 'RECOVERY.txt').write_text('Keep the HTTP/database hold while recovering. Restore exact previous controllers before dependencies via app rollback. No persistent SQL routines or triggers are changed. Verify original-column data and routine fingerprints via db rollback; retain compatible additive columns/indexes. Resume only after both recovery checks pass. Never replay business operations or restore data dumps over later activity.\n')
print(json.dumps({'release': str(release), 'environments': ['uat', 'prod'], 'php_files_per_environment': len(files), 'sql_sha256': manifest['sql_sha256']}))
