#!/usr/bin/env python3
"""Write ENCRYPTION_KEY from a .env file into dest as a sourceable env file."""
import sys
from pathlib import Path


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: write_soa_enc_env.py <env-path> <dest-path>", file=sys.stderr)
        return 2
    src = Path(sys.argv[1])
    dest = Path(sys.argv[2])
    key = None
    for raw in src.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        name, val = line.split("=", 1)
        name = name.strip()
        val = val.strip().strip("'").strip('"')
        if name in ("ENCRYPTION_KEY", "encryption.key") and val:
            key = val
            break
    if not key:
        print("ENCRYPTION_KEY not found", file=sys.stderr)
        return 1
    dest.write_text("ENCRYPTION_KEY='" + key.replace("'", "'\"'\"'") + "'\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
