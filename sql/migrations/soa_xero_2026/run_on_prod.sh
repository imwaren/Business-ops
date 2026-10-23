#!/usr/bin/env bash
# Runs on vps6 against production mytemr_master only.
set -eEuo pipefail

REMOTE_DIR="${REMOTE_DIR:-/tmp/soa_xero_prod}"
CSV_DIR="${REMOTE_DIR}/csv"
SQL_DIR="${REMOTE_DIR}/sql/migrations/soa_xero_2026"
TRIGGER_FILE="${REMOTE_DIR}/sql/general_ledger_triggers_master.sql"
ENC_FILE="${REMOTE_DIR}/enc.env"
STEP="${1:-all}"

MASTER_DB="${MASTER_DB:-mytemr_master}"
OAKRIDGE_DB="${OAKRIDGE_DB:-mytemr_diagnostic}"
MAC_DB="${MAC_DB:-mytemr_diagnostic_mactan}"
CONSO_DB="${CONSO_DB:-mytemr_diagnostic_conso}"
MOBILE_DB="${MOBILE_DB:-mytemr_diagnostic_mobile}"
MANTLEWOOD_DB="${MANTLEWOOD_DB:-mytemr_diagnostic_mantlewood}"

if [[ "${MASTER_DB}" != "mytemr_master" ]]; then
  echo "Refusing to run: MASTER_DB=${MASTER_DB} (expected mytemr_master)" >&2
  exit 1
fi

if [[ -z "${ENCRYPTION_KEY:-}" && -f "$ENC_FILE" ]]; then
  # shellcheck disable=SC1090
  source "$ENC_FILE"
fi
if [[ -z "${ENCRYPTION_KEY:-}" ]]; then
  echo "ENCRYPTION_KEY missing. Place it in ${ENC_FILE} as ENCRYPTION_KEY='...'" >&2
  exit 1
fi

export MASTER_DB OAKRIDGE_DB MAC_DB CONSO_DB MOBILE_DB MANTLEWOOD_DB ENCRYPTION_KEY
echo "MASTER_DB=${MASTER_DB} OAKRIDGE_DB=${OAKRIDGE_DB} MAC_DB=${MAC_DB} CONSO_DB=${CONSO_DB}"

mysql_cmd() {
  mysql --default-character-set=utf8mb4 --local-infile=1 "${MASTER_DB}" "$@"
}

run_sql_file() {
  echo "==> $1"
  mysql_cmd < "$1"
}

cleanup_secrets() {
  rm -f "$ENC_FILE" "${REMOTE_DIR}/soa.cnf"
}

restore_triggers() {
  echo "==> restore GL triggers (prod)"
  mysql_cmd < "$TRIGGER_FILE" || true
  cleanup_secrets
}

load_csvs() {
  python3 - "$CSV_DIR" "${MASTER_DB}" <<'PY'
import subprocess, sys
from pathlib import Path
csv_dir, db = sys.argv[1], sys.argv[2]
cols = [
    "ContactName","EmailAddress","POAddressLine1","POAddressLine2","POAddressLine3","POAddressLine4",
    "POCity","PORegion","POPostalCode","POCountry","SAAddressLine1","SAAddressLine2","SAAddressLine3",
    "SAAddressLine4","SACity","SARegion","SAPostalCode","SACountry","InvoiceNumber","Reference",
    "InvoiceDate","DueDate","PlannedDate","Total","TaxTotal","InvoiceAmountPaid","InvoiceAmountDue",
    "InventoryItemCode","Description","Quantity","UnitAmount","Discount","LineAmount","AccountCode",
    "TaxType","TaxAmount","TrackingName1","TrackingOption1","TrackingName2","TrackingOption2",
    "Currency","Type","Sent","Status",
]
files = sorted(
    p for p in Path(csv_dir).glob("*.csv")
    if not p.name.startswith("._")
)
if not files:
    raise SystemExit(f"no CSVs in {csv_dir}")
for path in files:
    print(f"LOAD {path.name}", flush=True)
    escaped = str(path).replace("\\", "\\\\").replace("'", "\\'")
    source = path.name.replace("\\", "\\\\").replace("'", "\\'")
    sql = (
        "LOAD DATA LOCAL INFILE '{path}' INTO TABLE soa_dump "
        "CHARACTER SET utf8mb4 "
        "FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\"' "
        "LINES TERMINATED BY '\\n' IGNORE 1 LINES "
        "({cols}) SET source_file = '{source}', "
        "Status = TRIM(TRAILING '\\r' FROM Status);"
    ).format(path=escaped, cols=",".join(cols), source=source)
    subprocess.check_call(["mysql", "--local-infile=1", db, "-e", sql])
print(f"loaded {len(files)} files")
PY
}

write_vars() {
  python3 - "$REMOTE_DIR/soa_vars.sql" <<'PY'
import os, sys
out = sys.argv[1]
def lit(name, default=""):
    v = os.environ.get(name, default)
    return v.replace("\\", "\\\\").replace("'", "\\'")
lines = [
    "SET NAMES utf8mb4;",
    "SET @soa_enc_key = '{}';".format(lit("ENCRYPTION_KEY")),
    "SET @soa_oakridge_db = '{}';".format(lit("OAKRIDGE_DB")),
    "SET @soa_mac_db = '{}';".format(lit("MAC_DB")),
    "SET @soa_conso_db = '{}';".format(lit("CONSO_DB")),
    "SET @soa_mobile_db = '{}';".format(lit("MOBILE_DB")),
    "SET @soa_mantlewood_db = '{}';".format(lit("MANTLEWOOD_DB")),
]
with open(out, "w", encoding="utf-8") as fh:
    fh.write("\n".join(lines) + "\n")
print("wrote", out)
PY
}

run_with_vars() {
  write_vars
  echo "==> $1"
  cat "${REMOTE_DIR}/soa_vars.sql" "$1" | mysql_cmd
}

if [[ "$STEP" != "all" && "$STEP" != "load" && "$STEP" != "schema" && "$STEP" != "map" && "$STEP" != "populate" && "$STEP" != "rematch" && "$STEP" != "verify" ]]; then
  echo "Usage: $0 [all|load|schema|map|populate|rematch|verify]" >&2
  exit 1
fi

if [[ "$STEP" == "all" || "$STEP" == "load" ]]; then
  mysql -e "SET GLOBAL local_infile=1;" || true
  run_sql_file "${SQL_DIR}/001_create_soa_dump.sql"
  load_csvs
  run_sql_file "${SQL_DIR}/002_dedup_soa_dump.sql"
fi
if [[ "$STEP" == "all" || "$STEP" == "schema" ]]; then
  run_sql_file "${SQL_DIR}/003_add_billing_migration_columns.sql"
fi
if [[ "$STEP" == "all" || "$STEP" == "map" ]]; then
  run_sql_file "${SQL_DIR}/005_map_companies.sql"
fi
if [[ "$STEP" == "all" || "$STEP" == "populate" ]]; then
  echo "==> pre-snapshot GL/JE"
  mysql_cmd -e "SELECT COUNT(*) AS gl_before FROM general_ledger WHERE is_deleted=0; SELECT COUNT(*) AS je_before FROM journal_entry WHERE is_deleted=0;"
  trap restore_triggers ERR EXIT
  run_sql_file "${SQL_DIR}/004_disable_gl_triggers.sql"
  run_with_vars "${SQL_DIR}/006_populate_billing.sql"
  run_sql_file "${SQL_DIR}/007_populate_payments.sql"
  trap - ERR EXIT
  restore_triggers
  run_sql_file "${SQL_DIR}/009_verify.sql"
fi
if [[ "$STEP" == "rematch" ]]; then
  echo "==> pre-snapshot GL/JE"
  mysql_cmd -e "SELECT COUNT(*) AS gl_before FROM general_ledger WHERE is_deleted=0; SELECT COUNT(*) AS je_before FROM journal_entry WHERE is_deleted=0;"
  trap restore_triggers ERR EXIT
  run_sql_file "${SQL_DIR}/004_disable_gl_triggers.sql"
  run_with_vars "${SQL_DIR}/008_apply_booking_match.sql"
  trap - ERR EXIT
  restore_triggers
  run_sql_file "${SQL_DIR}/009_verify.sql"
fi
if [[ "$STEP" == "verify" ]]; then
  run_sql_file "${SQL_DIR}/009_verify.sql"
fi

if [[ "$STEP" != "populate" && "$STEP" != "all" ]]; then
  cleanup_secrets
fi

echo "done step=${STEP}"
