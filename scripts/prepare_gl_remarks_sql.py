#!/usr/bin/env python3
"""Render environment-specific GL SQL into protected files outside the checkout."""
import argparse
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
KEY_TOKEN = '__GL_ENCRYPTION_KEY_HEX__'


def render_sql(source: str, encryption_key: bytes) -> str:
    if not encryption_key or KEY_TOKEN not in source:
        raise ValueError('A configured encryption key and an unrendered SQL template are required.')
    return source.replace(KEY_TOKEN, encryption_key.hex())


def read_environment_key(env_file: Path, php_binary: str) -> bytes:
    # Use the application's dotenv parser rather than guessing quoting/escaping.
    parser = ROOT / 'vendor/codeigniter4/framework/system/Config/DotEnv.php'
    code = r'''
require $argv[1];
(new CodeIgniter\Config\DotEnv(dirname($argv[2]), basename($argv[2])))->load();
$key = getenv('ENCRYPTION_KEY');
if ($key === false || $key === '') { $key = getenv('encryption.key'); }
if ($key === false || $key === '') { exit(1); }
fwrite(STDOUT, $key);
'''
    child_environment = os.environ.copy()
    child_environment.pop('ENCRYPTION_KEY', None)
    child_environment.pop('encryption.key', None)
    result = subprocess.run([php_binary, '-r', code, str(parser), str(env_file)],
                            capture_output=True, env=child_environment)
    if result.returncode or not result.stdout:
        raise ValueError('Unable to read the configured encryption key with the application dotenv parser.')
    return result.stdout


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--environment', choices=['uat', 'prod'], required=True)
    parser.add_argument('--env-file', type=Path, required=True)
    parser.add_argument('--output-dir', type=Path, required=True)
    parser.add_argument('--php', default='php')
    args = parser.parse_args()
    output_dir = args.output_dir.resolve()
    try:
        output_dir.relative_to(ROOT)
    except ValueError:
        pass
    else:
        parser.error('Rendered SQL contains a secret and must be written outside the checkout.')
    try:
        key = read_environment_key(args.env_file.resolve(strict=True), args.php)
        suffix = '_uat' if args.environment == 'uat' else ''
        names = [f'general_ledger_triggers{suffix}.sql', f'general_ledger_triggers_master{suffix}.sql']
        rendered = {name: render_sql((ROOT / 'sql' / name).read_text(), key) for name in names}
        output_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
        output_dir.chmod(0o700)
        for name, sql in rendered.items():
            target = output_dir / name
            temporary = target.with_suffix('.sql.tmp')
            descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            try:
                with os.fdopen(descriptor, 'w') as handle:
                    handle.write(sql)
                os.replace(temporary, target)
            finally:
                if temporary.exists():
                    temporary.unlink()
        print(f'Prepared {len(rendered)} {args.environment} SQL files in {output_dir}; no SQL was executed.')
        return 0
    except (OSError, ValueError):
        # Neither secret values nor parser output belongs in command logs.
        print('SQL preparation failed. Check the configuration path, PHP runtime, templates, and output permissions.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
