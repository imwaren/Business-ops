#!/usr/bin/env python3
"""One-off Xero GL → chunked CSVs (Jan–Aug 2026). Do not run against live DBs."""

from __future__ import annotations

import argparse
import csv
import re
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path

try:
    from openpyxl import load_workbook
except ImportError as exc:
    raise SystemExit(
        "openpyxl is required. From this folder: python3 -m venv .venv && "
        ".venv/bin/pip install openpyxl && .venv/bin/python generate_gl_jan2026.py"
    ) from exc

ROOT = Path(__file__).resolve().parent
REPO_ROOT = Path(__file__).resolve().parents[3]
XLSX = Path("/Users/myt_waren/Downloads/General Ledger_Jan2026.xlsx")
ACCOUNT_SQL = REPO_ROOT / "account_type.sql"
MAPPING_XLSX = Path(
    "/Users/myt_waren/Downloads/Account Type Mapping for GL Migration & Updated Chart of Accounts.xlsx"
)

MAPPING_SHEETS = {
    "jan": "GL_Jan2026_by_Account",
    "feb": "GL_Feb2026_by_Account",
    "mar": "GL_Mar2026_by_Account",
    "apr": "GL_Mar2026_by_Account",
    "may": "GL_Mar2026_by_Account",
    "jun": "GL_Mar2026_by_Account",
    "jul": "GL_Mar2026_by_Account",
    "aug": "GL_Mar2026_by_Account",
    "sep": "GL_Mar2026_by_Account",
}

# When the mapping sheet Code is blank, use COA Updated Xero CODE / Sub Group Code.
BLANK_CODE_FALLBACKS = {
    "tracking transfers": "890.B",
    "vehicle insurance": "890.B",
    "advances to lhpi": "102-231",
    "cash and cash equivalents": "100-114",
    "input tax vehicle insurance": "500-550-1",
    "interest receivable": "102-194",
    "management cost": "600-152",
    "final tax": "600-905",
    "check on hand": "100-004",
    "overtime cogs": "600-412",
    "retention payable current": "211-020A",
    "rounding": "700-400",
    "pre operating expenses": "600-172",
}

CHUNK = 500
JE_ID_START = 1
JE_ID_STARTS: dict[str, int] = {}
ADDED_ON = "2026-01-31 23:59:59"
PERIOD_LABEL = "Jan 2026"
ADDED_BY = 0

DB_META = {
    "mobile": {"db": "mytemr_diagnostic_mobile", "branch_id": 1, "clinic": True},
    "oakridge": {"db": "mytemr_diagnostic", "branch_id": 2, "clinic": True},
    "mactan": {"db": "mytemr_diagnostic_mactan", "branch_id": 3, "clinic": True},
    "consolacion": {"db": "mytemr_diagnostic_conso", "branch_id": 4, "clinic": True},
    "mantlewood": {"db": "mytemr_diagnostic_mantlewood", "branch_id": 7, "clinic": True},
    "master": {"db": "mytemr_master", "branch_id": None, "clinic": False},
}

LOCATION_TO_DB = {
    "mobile": "mobile",
    "oakridge": "oakridge",
    "mactan": "mactan",
    "consolacion": "consolacion",
    "mantle wood": "mantlewood",
    "mantlewood": "mantlewood",
}

SOURCE_MAP = {
    "manual journal": "journal_entry",
    "payable payment": "payable_payment",
    "payable invoice": "payable_invoice",
    "receivable invoice": "receivable_invoice",
    "receivable payment": "receivable_payment",
    "receivable credit note": "receivable_credit_note",
    "receivable credit note allocation": "receivable_credit_note_allocation",
    "receivable overpayment": "receivable_overpayment",
}

# Excel header → account_type.code (banks + names that will not match (BOC) alone)
RAW_ALIASES = {
    "Cash - BPI": "100-001",
    "Cash on Hand": "100-004",
    "Cash - BDO - CAPEX": "100-111",
    "Cash - BDO - OPEX": "100-115",
    "Cash - Land Bank - OPEX": "100-112",
    "Cash - Metrobank - OPEX": "100-110",
    "Cash - Security Bank CPCI": "100-003",
    "Cash - Security Bank CPCI - OPEX": "100-002",
    "Cash - Security Bank KPCI": "100-002",
    "Check on Hand": "100-004",
    "Overtime - COGS": "600-412",
    "Retention Payable - current": "211-020A",
    "Rounding": "700-400",
    "Pre Operating Expenses": "600-172",
}

CODE_IN_PARENS = re.compile(r"\(([0-9]{2,3}-[0-9A-Za-z]+)\)\s*$")
XERO_REF = re.compile(r"^#(\d+)\s*$")
SUFFIX_RE = re.compile(r"\s*\((boc|cos|ga|cogs)\)\s*$", re.I)
SKIP_HEADER = re.compile(r"^(total\b|net movement\b)", re.I)

GL_COLS = [
    "branch_id",
    "source",
    "source_id",
    "account_type_id",
    "date",
    "debit",
    "credit",
    "remark",
    "is_migrated",
    "added_by",
    "added_on",
    "is_deleted",
]
JE_COLS = [
    "id",
    "cost_center_id",
    "date",
    "type",
    "remarks",
    "total_debit",
    "total_credit",
    "status",
    "is_migrated",
    "added_by",
    "added_on",
    "is_deleted",
]
JE_ITEM_COLS = [
    "journal_entry_id",
    "account_type_id",
    "debit",
    "credit",
    "remarks",
    "added_by",
    "added_on",
    "is_deleted",
]


def split_sql_values(s: str) -> list:
    out = []
    i = 0
    n = len(s)
    while i < n:
        while i < n and s[i] in " \t\r\n":
            i += 1
        if i >= n:
            break
        if s[i] == "'":
            j = i + 1
            buf = []
            while j < n:
                if s[j] == "\\" and j + 1 < n:
                    buf.append(s[j + 1])
                    j += 2
                    continue
                if s[j] == "'":
                    if j + 1 < n and s[j + 1] == "'":
                        buf.append("'")
                        j += 2
                        continue
                    j += 1
                    break
                buf.append(s[j])
                j += 1
            out.append("".join(buf))
            i = j
            while i < n and s[i] in " \t\r\n":
                i += 1
            if i < n and s[i] == ",":
                i += 1
            continue
        j = s.find(",", i)
        if j < 0:
            token = s[i:].strip()
            if token:
                out.append(None if token.upper() == "NULL" else token)
            break
        token = s[i:j].strip()
        out.append(None if token.upper() == "NULL" else token)
        i = j + 1
    return out


def parse_account_type_sql(path: Path) -> list[dict]:
    sql = path.read_text(encoding="utf-8", errors="replace")
    blocks = re.findall(r"INSERT INTO `account_type`.*?VALUES\s*(.*?);", sql, flags=re.S | re.I)
    leaves = []
    for block in blocks:
        depth = 0
        start = None
        in_quote = False
        i = 0
        n = len(block)
        while i < n:
            ch = block[i]
            if in_quote:
                if ch == "\\" and i + 1 < n:
                    i += 2
                    continue
                if ch == "'":
                    if i + 1 < n and block[i + 1] == "'":
                        i += 2
                        continue
                    in_quote = False
                i += 1
                continue
            if ch == "'":
                in_quote = True
                i += 1
                continue
            if ch == "(":
                if depth == 0:
                    start = i + 1
                depth += 1
            elif ch == ")":
                depth -= 1
                if depth == 0 and start is not None:
                    vals = split_sql_values(block[start:i])
                    if len(vals) >= 9 and vals[1] is None:
                        name = str(vals[3]).strip()
                        code = vals[8]
                        if isinstance(code, str):
                            code = code.strip()
                        if code in ("", "NULL"):
                            code = None
                        leaves.append(
                            {
                                "id": int(vals[0]),
                                "name": name,
                                "code": code,
                            }
                        )
                    start = None
            i += 1
    return leaves


def norm_name(s: str) -> str:
    s = (s or "").lower().replace("&", " and ")
    s = re.sub(r"[^a-z0-9/]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def strip_cost_suffix(name: str) -> str:
    return SUFFIX_RE.sub("", name or "").strip()


EXPLICIT_ALIASES = {norm_name(k): v for k, v in RAW_ALIASES.items()}


def prefer_boc(candidates: list[dict]) -> dict | None:
    if not candidates:
        return None
    if len(candidates) == 1:
        return candidates[0]
    boc = [c for c in candidates if re.search(r"\(boc\)\s*$", c["name"], re.I)]
    if len(boc) == 1:
        return boc[0]
    if boc:
        return sorted(boc, key=lambda c: c["id"])[0]
    return sorted(candidates, key=lambda c: c["id"])[0]


def mapping_sheet_name(label: str, out: Path) -> str:
    text = f"{label} {out.name}".lower()
    for key in sorted(MAPPING_SHEETS, key=len, reverse=True):
        if key in text:
            return MAPPING_SHEETS[key]
    return MAPPING_SHEETS["jan"]


def parse_je_id_starts(raw: str | None, default: int) -> dict[str, int]:
    starts = {key: default for key in DB_META}
    if not raw:
        return starts
    for part in raw.split(","):
        part = part.strip()
        if not part:
            continue
        if "=" not in part:
            raise SystemExit(f"Invalid --je-id-starts item: {part}")
        key, value = part.split("=", 1)
        key = key.strip()
        if key not in DB_META:
            raise SystemExit(f"Unknown DB in --je-id-starts: {key}")
        starts[key] = int(value.strip())
    return starts


def sql_code_cell(value) -> str:
    if value is None:
        return ""
    if isinstance(value, float) and value == int(value):
        return str(int(value))
    return str(value).strip()


def load_coa_updated_codes(xlsx: Path) -> dict[str, str]:
    if not xlsx.exists():
        return {}
    wb = load_workbook(xlsx, read_only=True, data_only=True)
    if "COA Updated" not in wb.sheetnames:
        wb.close()
        return {}
    ws = wb["COA Updated"]
    filled: dict[str, str] = {}
    for row in ws.iter_rows(min_row=2, values_only=True):
        name = row[3] if row and len(row) > 3 else None
        if not name:
            continue
        xero = sql_code_cell(row[2] if len(row) > 2 else None)
        sub = sql_code_cell(row[1] if len(row) > 1 else None)
        code = xero or sub
        if code:
            filled[norm_name(str(name))] = code
    wb.close()
    return filled


def load_mapping_codes(xlsx: Path, sheet: str, coa_updated: dict[str, str]) -> dict[str, str]:
    if not xlsx.exists():
        raise SystemExit(f"Missing mapping workbook: {xlsx}")
    wb = load_workbook(xlsx, read_only=True, data_only=True)
    if sheet not in wb.sheetnames:
        wb.close()
        raise SystemExit(f"Missing mapping sheet {sheet} in {xlsx}")
    ws = wb[sheet]
    filled: dict[str, str] = {}
    for row in ws.iter_rows(min_row=2, values_only=True):
        excel_name = row[0] if row else None
        if not excel_name or not str(excel_name).strip():
            continue
        code = sql_code_cell(row[1] if len(row) > 1 else None)
        key = norm_name(str(excel_name))
        if not code:
            acct_name = row[3] if len(row) > 3 else None
            code = (
                coa_updated.get(key)
                or (coa_updated.get(norm_name(str(acct_name))) if acct_name else "")
                or BLANK_CODE_FALLBACKS.get(key, "")
            )
        if code:
            filled[key] = code
    wb.close()
    return filled


def build_coa_indexes(leaves: list[dict]):
    by_code: dict[str, list[dict]] = defaultdict(list)
    by_norm: dict[str, list[dict]] = defaultdict(list)
    by_base: dict[str, list[dict]] = defaultdict(list)
    for leaf in leaves:
        if leaf["code"]:
            by_code[leaf["code"]].append(leaf)
        by_norm[norm_name(leaf["name"])].append(leaf)
        by_base[norm_name(strip_cost_suffix(leaf["name"]))].append(leaf)
    return by_code, by_norm, by_base


def pick_leaf_by_code(code: str, excel_name: str, by_code) -> dict | None:
    cands = by_code.get(code) or []
    if not cands:
        return None
    if len(cands) == 1:
        return cands[0]
    key = norm_name(excel_name)
    for leaf in cands:
        if norm_name(leaf["name"]) == key:
            return leaf
    base = norm_name(strip_cost_suffix(excel_name))
    for leaf in cands:
        if norm_name(strip_cost_suffix(leaf["name"])) == base:
            return leaf
    return prefer_boc(cands)


def resolve_account(excel_name: str, by_code, by_norm, by_base, mapping_codes: dict) -> tuple[dict | None, str]:
    name = (excel_name or "").strip()
    if not name:
        return None, ""

    key = norm_name(name)
    if key in mapping_codes:
        leaf = pick_leaf_by_code(mapping_codes[key], name, by_code)
        if leaf:
            return leaf, "mapping"

    m = CODE_IN_PARENS.search(name)
    if m:
        leaf = pick_leaf_by_code(m.group(1), name, by_code)
        if leaf:
            return leaf, "code"

    if key in EXPLICIT_ALIASES:
        leaf = pick_leaf_by_code(EXPLICIT_ALIASES[key], name, by_code)
        if leaf:
            return leaf, "alias"

    exact = by_norm.get(key)
    if exact:
        return prefer_boc(exact), "name"

    base = by_base.get(key)
    if base:
        return prefer_boc(base), "alias"

    base_key = norm_name(strip_cost_suffix(name))
    if base_key != key:
        base = by_base.get(base_key)
        if base:
            return prefer_boc(base), "alias"

    return None, ""


def excel_date(value) -> str | None:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d")
    if isinstance(value, (int, float)) and 30000 < value < 80000:
        dt = datetime(1899, 12, 30) + timedelta(days=float(value))
        return dt.strftime("%Y-%m-%d")
    if isinstance(value, str):
        s = value.strip()
        for fmt in ("%Y-%m-%d", "%d/%m/%Y", "%m/%d/%Y", "%d-%b-%Y"):
            try:
                return datetime.strptime(s, fmt).strftime("%Y-%m-%d")
            except ValueError:
                pass
    return None


def money(value) -> float:
    if value is None or value == "":
        return 0.0
    if isinstance(value, (int, float)):
        return float(value)
    s = str(value).replace(",", "").replace("(", "-").replace(")", "").strip()
    if s in ("", "-"):
        return 0.0
    try:
        return float(s)
    except ValueError:
        return 0.0


def clean_text(value) -> str:
    if value is None:
        return ""
    return re.sub(r"[\r\n\t]+", " ", str(value)).strip()


def target_db(location: str | None) -> str:
    key = (location or "").strip().lower()
    return LOCATION_TO_DB.get(key, "master")


def xero_source_id(reference: str) -> int:
    m = XERO_REF.match((reference or "").strip())
    return int(m.group(1)) if m else 0


def je_group_key(txn: dict) -> str:
    if txn["xero_id"]:
        return f"xero:{txn['xero_id']}"
    ref = (txn.get("reference") or "").strip()
    if ref:
        return f"ref:{ref}"
    return f"row:{txn['excel_row']}"


def source_key(excel_source: str) -> str:
    return SOURCE_MAP.get((excel_source or "").strip().lower(), re.sub(r"[^a-z0-9]+", "_", (excel_source or "").strip().lower()).strip("_"))


def csv_cell(value):
    if value is None:
        return r"\N"
    return value


def write_chunks(folder: Path, prefix: str, header: list[str], rows: list[list], always_numbered: bool = False) -> list[str]:
    folder.mkdir(parents=True, exist_ok=True)
    names = []
    if not rows:
        return names
    if len(rows) <= CHUNK and not always_numbered:
        name = f"{prefix}.csv"
        path = folder / name
        with path.open("w", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh, lineterminator="\n")
            w.writerow(header)
            w.writerows(rows)
        names.append(name)
        return names
    for i in range(0, len(rows), CHUNK):
        chunk = rows[i : i + CHUNK]
        name = f"{prefix}_{i // CHUNK + 1:03d}.csv"
        path = folder / name
        with path.open("w", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh, lineterminator="\n")
            w.writerow(header)
            w.writerows(chunk)
        names.append(name)
    return names


def write_import_sql(folder: Path, db_key: str, je_files: list[str], item_files: list[str], gl_files: list[str], master_only: bool = False) -> None:
    meta = DB_META[db_key]
    lines = [
        f"-- Import {PERIOD_LABEL} migrated GL into {meta['db']} ({db_key}"
        + (f", branch_id {meta['branch_id']}" if meta["branch_id"] else ", branch_id NULL")
        + ")",
        "-- Do not import through the live API.",
        "--",
        "-- Once per database (from the month folder, before the first clinic load):",
        f"--   mysql -u root -p {meta['db']} < ../001_add_is_migrated.sql",
        "-- If LOAD DATA LOCAL fails:",
        "--   mysql -u root -p -e \"SET GLOBAL local_infile=1;\"",
        "--",
        "-- Then from this directory:",
        f"--   mysql --local-infile=1 -u root -p {meta['db']} < import.sql",
        "-- Example (Oakridge / Jan on the app server):",
        "--   cd /home/mytemr/web/accounting-back.myt-emr.com/public_html/sql/migrations/gl_jan2026/oakridge",
        "--   mysql --local-infile=1 -u root -p mytemr_diagnostic < import.sql",
        "-- --local-infile=1 is required. Without it, LOAD DATA LOCAL fails.",
        "",
        "SET NAMES utf8mb4;",
        "SET SESSION foreign_key_checks = 0;",
        "",
    ]
    if master_only:
        lines = [
            f"-- Import {PERIOD_LABEL} into mytemr_master only; existing schema/flags required.",
            "-- Verify the generated journal_entry IDs are unused before importing.",
            "-- Not safe to import twice. Do not use mysql --force; inspect warnings/errors.",
            f"-- cd /home/mytemr/web/accounting-back.myt-emr.com/public_html/sql/migrations/{ROOT.name}/master",
            "-- mysql --local-infile=1 -u root -p mytemr_master < import.sql",
            "", "SET NAMES utf8mb4;", "SET SESSION foreign_key_checks = 0;", "",
        ]
    if meta["clinic"]:
        lines += [
            "-- Drop JE posters so LOAD/INSERT does not double-post GL",
            "DROP TRIGGER IF EXISTS trg_gl_journal_entry_ai;",
            "DROP TRIGGER IF EXISTS trg_gl_journal_entry_au;",
            "DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_ai;",
            "DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_au;",
            "",
            "-- Recreate triggers after import from sql/general_ledger_triggers.sql",
            "",
        ]
    else:
        lines += [
            "-- Master: load journal_entry only if that table exists on mytemr_master.",
            "",
        ]

    def load_block(filename: str, table: str, cols: list[str]) -> None:
        col_list = ", ".join(cols)
        lines.append(f"LOAD DATA LOCAL INFILE '{filename}'")
        lines.append(f"INTO TABLE {table}")
        lines.append("CHARACTER SET utf8mb4")
        lines.append("FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' ESCAPED BY '\"'")
        lines.append("LINES TERMINATED BY '\\n'")
        lines.append("IGNORE 1 LINES")
        lines.append(f"({col_list});")
        lines.append("")

    if meta["clinic"] or je_files:
        for name in je_files:
            load_block(name, "journal_entry", JE_COLS)
        for name in item_files:
            load_block(name, "journal_entry_item", JE_ITEM_COLS)
    for name in gl_files:
        load_block(name, "general_ledger", GL_COLS)

    lines += [
        "SET SESSION foreign_key_checks = 1;",
        "",
    ]
    if meta["clinic"]:
        lines += [
            "-- Recreate trg_gl_journal_entry_* from sql/general_ledger_triggers.sql",
            "",
        ]
    (folder / "import.sql").write_text("\n".join(lines), encoding="utf-8")


def clear_generated_outputs() -> None:
    for db_key in DB_META:
        folder = ROOT / db_key
        if folder.is_dir():
            for p in folder.iterdir():
                if p.is_file() and (
                    p.name.startswith("general_ledger")
                    or p.name.startswith("journal_entry")
                    or p.name == "import.sql"
                ):
                    p.unlink()
        else:
            folder.mkdir(parents=True, exist_ok=True)
    for name in ("account_map.csv", "unmapped_accounts.csv", "skipped_rows.csv"):
        path = ROOT / name
        if path.exists():
            path.unlink()


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Generate Xero GL migration CSVs")
    p.add_argument("--xlsx", type=Path, default=XLSX, help="General Ledger Detail workbook")
    p.add_argument("--out", type=Path, default=ROOT, help="Output folder (account_map + per-DB CSVs)")
    p.add_argument("--mapping-xlsx", type=Path, default=MAPPING_XLSX, help="Excel-name → Xero code workbook")
    p.add_argument("--je-id-start", type=int, default=JE_ID_START)
    p.add_argument(
        "--je-id-starts",
        default="",
        help="Per-DB starts, e.g. mobile=19689,oakridge=209084 (overrides --je-id-start)",
    )
    p.add_argument("--added-on", default=ADDED_ON)
    p.add_argument("--label", default=PERIOD_LABEL, help="Period label in import.sql comments")
    p.add_argument("--master-only", action="store_true", help="Route every source row to master and emit only master files")
    return p.parse_args()


def apply_config(args: argparse.Namespace) -> None:
    global ROOT, XLSX, MAPPING_XLSX, JE_ID_START, JE_ID_STARTS, ADDED_ON, PERIOD_LABEL
    ROOT = args.out.resolve()
    XLSX = args.xlsx.expanduser()
    MAPPING_XLSX = args.mapping_xlsx.expanduser()
    JE_ID_START = args.je_id_start
    JE_ID_STARTS = parse_je_id_starts(args.je_id_starts or None, args.je_id_start)
    ADDED_ON = args.added_on
    PERIOD_LABEL = args.label
    ROOT.mkdir(parents=True, exist_ok=True)


def main() -> None:
    args = parse_args()
    apply_config(args)
    if args.master_only:
        # Restrict cleanup, ID assignment, and output as well as row routing.
        for key in list(DB_META):
            if key != "master":
                del DB_META[key]
    print(f"Period {PERIOD_LABEL} xlsx={XLSX} out={ROOT} je_id_starts={JE_ID_STARTS}")
    if not XLSX.exists():
        raise SystemExit(f"Missing Excel: {XLSX}")
    if not ACCOUNT_SQL.exists():
        raise SystemExit(f"Missing COA SQL: {ACCOUNT_SQL}")

    leaves = parse_account_type_sql(ACCOUNT_SQL)
    by_code, by_norm, by_base = build_coa_indexes(leaves)
    sheet = mapping_sheet_name(PERIOD_LABEL, ROOT)
    coa_updated = load_coa_updated_codes(MAPPING_XLSX)
    mapping_codes = load_mapping_codes(MAPPING_XLSX, sheet, coa_updated)

    print(f"COA leaves: {len(leaves)}")
    print(f"Mapping sheet {sheet}: {len(mapping_codes)} excel_name → code")

    wb = load_workbook(XLSX, read_only=True, data_only=True)
    ws = wb["General Ledger Detail"]

    current_account = None
    excel_names: dict[str, dict] = {}
    txns = []
    skipped = []
    row_no = 0

    for row in ws.iter_rows(min_row=6, values_only=True):
        row_no += 1
        excel_row = row_no + 5
        a = row[0] if row else None
        source = row[1] if row and len(row) > 1 else None
        desc = row[2] if row and len(row) > 2 else None
        ref = row[3] if row and len(row) > 3 else None
        debit = row[4] if row and len(row) > 4 else None
        credit = row[5] if row and len(row) > 5 else None
        location = row[10] if row and len(row) > 10 else None

        date_s = excel_date(a)
        if date_s and source:
            excel_source = clean_text(source)
            description = clean_text(desc)
            reference = clean_text(ref)
            loc = clean_text(location)
            db_key = "master" if args.master_only else target_db(loc)
            src = source_key(excel_source)
            remark_parts = [p for p in (description, reference) if p]
            remark = " — ".join(remark_parts)
            acc_name = current_account or ""
            resolved, match = resolve_account(acc_name, by_code, by_norm, by_base, mapping_codes)
            info = excel_names.setdefault(
                acc_name,
                {"excel_name": acc_name, "rows": 0, "account": None, "match": ""},
            )
            info["rows"] += 1
            if resolved:
                info["account"] = resolved
                info["match"] = match
                txns.append(
                    {
                        "excel_row": excel_row,
                        "excel_name": acc_name,
                        "db": db_key,
                        "branch_id": DB_META[db_key]["branch_id"],
                        "source": src,
                        "excel_source": excel_source,
                        "xero_id": xero_source_id(reference),
                        "account_type_id": resolved["id"],
                        "date": date_s,
                        "debit": money(debit),
                        "credit": money(credit),
                        "remark": remark,
                        "description": description,
                        "reference": reference,
                        "location": loc,
                    }
                )
            else:
                skipped.append(
                    {
                        "excel_row": excel_row,
                        "excel_name": acc_name,
                        "source": excel_source,
                        "description": description,
                        "reference": reference,
                        "date": date_s,
                        "debit": money(debit),
                        "credit": money(credit),
                        "location": loc,
                        "reason": "unmapped_account",
                    }
                )
            continue

        if isinstance(a, str):
            label = a.strip()
            if not label or SKIP_HEADER.match(label):
                continue
            if source is None:
                current_account = label
                info = excel_names.setdefault(
                    current_account,
                    {"excel_name": current_account, "rows": 0, "account": None, "match": ""},
                )
                if info["account"] is None:
                    resolved, match = resolve_account(
                        current_account, by_code, by_norm, by_base, mapping_codes
                    )
                    if resolved:
                        info["account"] = resolved
                        info["match"] = match

    wb.close()

    # Re-resolve headers that had no rows yet / ensure match after all aliases
    for info in excel_names.values():
        if info["account"] is None:
            resolved, match = resolve_account(info["excel_name"], by_code, by_norm, by_base, mapping_codes)
            if resolved:
                info["account"] = resolved
                info["match"] = match

    unmapped = [info for info in excel_names.values() if info["account"] is None]
    if unmapped:
        names = ", ".join(sorted(i["excel_name"] for i in unmapped))
        raise SystemExit(
            f"Unmapped GL headers ({len(unmapped)}): {names}. "
            "Fix the mapping workbook or COA dump; unmapped_accounts.csv is not written."
        )

    clear_generated_outputs()

    map_rows = sorted(excel_names.values(), key=lambda r: r["excel_name"].lower())
    with (ROOT / "account_map.csv").open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(["excel_name", "account_type_code", "account_type_id", "account_type_name", "match"])
        for info in map_rows:
            acc = info["account"]
            w.writerow(
                [
                    info["excel_name"],
                    acc["code"] if acc else "",
                    acc["id"] if acc else "",
                    acc["name"] if acc else "",
                    info["match"],
                ]
            )

    gl_by_db = defaultdict(list)
    je_groups = defaultdict(list)  # (db, type, group_key) -> lines

    for txn in txns:
        je_groups[(txn["db"], txn["source"], je_group_key(txn))].append(txn)

    je_by_db = defaultdict(list)
    je_item_by_db = defaultdict(list)

    for db_key in DB_META:
        next_id = JE_ID_STARTS.get(db_key, JE_ID_START)
        keys = sorted(
            [k for k in je_groups if k[0] == db_key],
            key=lambda k: (
                min(t["date"] for t in je_groups[k]),
                k[1],
                str(k[2]),
            ),
        )
        for key in keys:
            lines = sorted(je_groups[key], key=lambda t: (t["date"], t["excel_row"]))
            je_id = next_id
            next_id += 1
            first = lines[0]
            total_debit = sum(t["debit"] for t in lines)
            total_credit = sum(t["credit"] for t in lines)
            je_by_db[db_key].append(
                [
                    je_id,
                    csv_cell(first["branch_id"]),
                    first["date"],
                    first["source"],
                    first["remark"],
                    f"{total_debit:.4f}",
                    f"{total_credit:.4f}",
                    "approved",
                    1,
                    ADDED_BY,
                    ADDED_ON,
                    0,
                ]
            )
            for t in lines:
                je_item_by_db[db_key].append(
                    [
                        je_id,
                        t["account_type_id"],
                        f"{t['debit']:.4f}",
                        f"{t['credit']:.4f}",
                        t["remark"],
                        ADDED_BY,
                        ADDED_ON,
                        0,
                    ]
                )
                gl_by_db[db_key].append(
                    {
                        **t,
                        "source": "journal_entry",
                        "source_id": je_id,
                    }
                )

    summary = []
    for db_key, meta in DB_META.items():
        folder = ROOT / db_key
        folder.mkdir(parents=True, exist_ok=True)
        gl_rows_src = sorted(gl_by_db[db_key], key=lambda t: (t["date"], t["excel_row"]))
        gl_rows = []
        for t in gl_rows_src:
            gl_rows.append(
                [
                    csv_cell(t["branch_id"]),
                    "journal_entry",
                    t["source_id"],
                    t["account_type_id"],
                    t["date"],
                    f"{t['debit']:.4f}",
                    f"{t['credit']:.4f}",
                    t["remark"],
                    1,
                    ADDED_BY,
                    ADDED_ON,
                    0,
                ]
            )
        numbered = len(gl_rows) > CHUNK
        gl_files = write_chunks(folder, "general_ledger", GL_COLS, gl_rows, always_numbered=numbered)
        je_files = write_chunks(folder, "journal_entry", JE_COLS, je_by_db[db_key])
        item_files = write_chunks(folder, "journal_entry_item", JE_ITEM_COLS, je_item_by_db[db_key])
        write_import_sql(folder, db_key, je_files, item_files, gl_files, master_only=args.master_only)
        summary.append(
            f"{db_key:12} gl={len(gl_rows):5} je={len(je_by_db[db_key]):4} items={len(je_item_by_db[db_key]):4} files={len(gl_files)+len(je_files)+len(item_files)}"
        )

    mapped_headers = sum(1 for i in map_rows if i["account"])
    mapped_txns = len(txns)
    print(f"Excel account headers: {len(map_rows)} mapped={mapped_headers} unmapped=0")
    print(f"Txn rows mapped={mapped_txns} skipped={len(skipped)}")
    for line in summary:
        print(line)
    print(f"Wrote {ROOT}")


if __name__ == "__main__":
    main()
