#!/usr/bin/env python3
"""One-off 2026 Beginning TB → master journal_entry + general_ledger CSVs.

Do not run the generated import.sql against live DBs from this script.
Stops without LOAD files if any nonzero Excel code is missing from the COA.
"""

from __future__ import annotations

import argparse
import csv
import re
import sys
import zipfile
import xml.etree.ElementTree as ET
from collections import defaultdict
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

ROOT = Path(__file__).resolve().parent
REPO_ROOT = Path(__file__).resolve().parents[3]
XLSX = Path("/Users/myt_waren/Downloads/2026 Trial Balance Beg Balance.xlsx")
ACCOUNT_SQL = REPO_ROOT / "account_type.sql"

JE_ID_START = 947
ITEM_ID_START = 7205
GL_ID_START = 13816
DATE = "2025-12-31"
ADDED_ON = "2025-12-31 23:59:59"
REMARK = "2026 Beginning"
ADDED_BY = 0
SHEET_NAME = "WTB"
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
Q4 = Decimal("0.0001")

# Excel 104-020 is used twice; COA split those into A/B.
INVENTORY_104_020 = {
    "drugs and medicines inventory": "104-020A",
    "medical supplies inventory": "104-020B",
}

# TB codes that reuse an existing COA leaf.
CODE_ALIASES = {
    "100-113": "100-004",
    "100-107": "100-003",
}

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
    "id",
    "journal_entry_id",
    "account_type_id",
    "debit",
    "credit",
    "remarks",
    "added_by",
    "added_on",
    "is_deleted",
]
GL_COLS = [
    "id",
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


def build_coa_indexes(leaves: list[dict]):
    by_code: dict[str, list[dict]] = defaultdict(list)
    by_code_ci: dict[str, list[dict]] = defaultdict(list)
    for leaf in leaves:
        if not leaf["code"]:
            continue
        by_code[leaf["code"]].append(leaf)
        by_code_ci[leaf["code"].casefold()].append(leaf)
    return by_code, by_code_ci


def norm_name(s: str) -> str:
    s = (s or "").lower().replace("&", " and ")
    s = re.sub(r"[^a-z0-9/]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def money(value) -> Decimal:
    if value is None or value == "":
        return Decimal("0")
    if isinstance(value, Decimal):
        return value
    if isinstance(value, (int, float)):
        return Decimal(str(value))
    s = str(value).replace(",", "").replace("(", "-").replace(")", "").strip()
    if s in ("", "-"):
        return Decimal("0")
    return Decimal(s)


def q4(value: Decimal) -> Decimal:
    return value.quantize(Q4, rounding=ROUND_HALF_UP)


def fmt_money(value: Decimal) -> str:
    return f"{q4(value):.4f}"


def csv_cell(value):
    if value is None:
        return r"\N"
    return value


def cell_value(c, shared_strings):
    t = c.attrib.get("t")
    v = c.find("m:v", NS)
    if v is None:
        return None
    val = v.text
    if t == "s" and val is not None:
        return shared_strings[int(val)]
    return val


def load_shared_strings(zf: zipfile.ZipFile) -> list[str]:
    if "xl/sharedStrings.xml" not in zf.namelist():
        return []
    root = ET.fromstring(zf.read("xl/sharedStrings.xml"))
    out = []
    for si in root.findall("m:si", NS):
        texts = [t.text or "" for t in si.findall(".//m:t", NS)]
        out.append("".join(texts))
    return out


def sheet_path(zf: zipfile.ZipFile, sheet_name: str) -> str:
    wb = ET.fromstring(zf.read("xl/workbook.xml"))
    rels = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))
    rid_to_target = {rel.attrib.get("Id"): rel.attrib.get("Target") for rel in rels}
    r_ns = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id"
    for sh in wb.findall("m:sheets/m:sheet", NS):
        if sh.attrib.get("name") == sheet_name:
            target = rid_to_target[sh.attrib[r_ns]]
            return "xl/" + target.lstrip("/")
    raise SystemExit(f"Missing sheet {sheet_name} in {XLSX}")


def parse_tb_rows(xlsx: Path) -> list[dict]:
    with zipfile.ZipFile(xlsx) as zf:
        shared = load_shared_strings(zf)
        ws = ET.fromstring(zf.read(sheet_path(zf, SHEET_NAME)))
        rows = []
        for row in ws.findall("m:sheetData/m:row", NS):
            r = int(row.attrib.get("r", "0"))
            cells = {}
            for c in row.findall("m:c", NS):
                ref = c.attrib.get("r") or ""
                m = re.match(r"([A-Z]+)", ref)
                if not m:
                    continue
                cells[m.group(1)] = cell_value(c, shared)
            if r <= 2:
                continue
            code = str(cells.get("B") or "").strip()
            name = str(cells.get("C") or "").strip()
            raw = cells.get("N")
            try:
                net = money(raw)
            except Exception:
                net = None
            rows.append(
                {
                    "excel_row": r,
                    "code": code,
                    "name": name,
                    "net": net,
                    "raw": raw,
                }
            )
    return rows


def resolve_account(code: str, name: str, by_code, by_code_ci) -> tuple[dict | None, str]:
    if code == "104-020":
        mapped = INVENTORY_104_020.get(norm_name(name))
        if mapped:
            cands = by_code.get(mapped) or []
            if len(cands) == 1:
                return cands[0], "104-020-name"
        return None, ""

    lookup = CODE_ALIASES.get(code, code)
    match = "alias" if lookup != code else "code"
    if lookup in by_code and len(by_code[lookup]) == 1:
        return by_code[lookup][0], match

    folded = lookup.casefold()
    if folded in by_code_ci and len(by_code_ci[folded]) == 1:
        return by_code_ci[folded][0], "alias" if lookup != code else "code-ci"

    return None, ""


def write_csv(path: Path, header: list[str], rows: list[list]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh, lineterminator="\n")
        w.writerow(header)
        w.writerows(rows)


def write_import_sql(folder: Path, je_file: str, item_file: str, gl_file: str) -> None:
    folder.mkdir(parents=True, exist_ok=True)
    lines = [
        "-- Import 2026 Beginning TB into mytemr_master (master, branch_id NULL)",
        "-- Date 2025-12-31. Do not import through the live API.",
        "--",
        "-- is_migrated was added by sql/migrations/gl_jan2026/001_add_is_migrated.sql",
        "-- Run ../003_add_tb_accounts.sql on mytemr_master (and clinic DBs) BEFORE this LOAD",
        "-- so account_type ids 1801 (AR - MSC) and 1805 (LOANS PAYABLE - BDO) exist.",
        "-- If LOAD DATA LOCAL fails:",
        '--   mysql -u root -p -e "SET GLOBAL local_infile=1;"',
        "--",
        "-- Then from this directory:",
        "--   mysql --local-infile=1 -u root -p mytemr_master < import.sql",
        "",
        "SET NAMES utf8mb4;",
        "SET SESSION foreign_key_checks = 0;",
        "",
        "-- Master: no JE→GL triggers. Load journal_entry, items, then general_ledger.",
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

    load_block(je_file, "journal_entry", JE_COLS)
    load_block(item_file, "journal_entry_item", JE_ITEM_COLS)
    load_block(gl_file, "general_ledger", GL_COLS)
    lines += [
        "SET SESSION foreign_key_checks = 1;",
        "",
    ]
    (folder / "import.sql").write_text("\n".join(lines), encoding="utf-8")


def clear_load_outputs(folder: Path) -> None:
    if not folder.is_dir():
        return
    for p in folder.iterdir():
        if p.is_file() and (
            p.name.startswith("journal_entry")
            or p.name.startswith("general_ledger")
            or p.name == "import.sql"
        ):
            p.unlink()


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Generate 2026 Beginning TB CSVs for master")
    p.add_argument("--xlsx", type=Path, default=XLSX)
    p.add_argument("--out", type=Path, default=ROOT)
    p.add_argument("--je-id-start", type=int, default=JE_ID_START)
    p.add_argument("--item-id-start", type=int, default=ITEM_ID_START)
    p.add_argument("--gl-id-start", type=int, default=GL_ID_START)
    return p.parse_args()


def main() -> int:
    args = parse_args()
    out = args.out.resolve()
    xlsx = args.xlsx.expanduser()
    out.mkdir(parents=True, exist_ok=True)
    master_dir = out / "master"

    print(
        f"2026 Beginning xlsx={xlsx} out={out} "
        f"je={args.je_id_start} item={args.item_id_start} gl={args.gl_id_start}"
    )
    if not xlsx.exists():
        raise SystemExit(f"Missing Excel: {xlsx}")
    if not ACCOUNT_SQL.exists():
        raise SystemExit(f"Missing COA SQL: {ACCOUNT_SQL}")

    leaves = parse_account_type_sql(ACCOUNT_SQL)
    by_code, by_code_ci = build_coa_indexes(leaves)
    print(f"COA leaves: {len(leaves)}")

    tb_rows = parse_tb_rows(xlsx)
    mapped = []
    unmapped = []
    account_map_rows = []
    seen_map = set()

    for row in tb_rows:
        if row["net"] is None:
            unmapped.append({**row, "reason": "unparseable_amount"})
            continue
        if row["net"] == 0:
            continue
        if not row["code"]:
            unmapped.append({**row, "reason": "blank_code"})
            continue

        leaf, match = resolve_account(row["code"], row["name"], by_code, by_code_ci)
        if not leaf:
            unmapped.append({**row, "reason": "no_coa_match"})
            continue

        if row["net"] > 0:
            debit, credit = q4(row["net"]), Decimal("0.0000")
        else:
            debit, credit = Decimal("0.0000"), q4(-row["net"])

        mapped.append(
            {
                **row,
                "leaf": leaf,
                "match": match,
                "debit": debit,
                "credit": credit,
            }
        )
        key = (row["code"], row["name"], leaf["id"])
        if key not in seen_map:
            seen_map.add(key)
            account_map_rows.append(
                [
                    row["code"],
                    row["name"],
                    leaf["code"],
                    leaf["id"],
                    leaf["name"],
                    match,
                ]
            )

    write_csv(
        out / "account_map.csv",
        [
            "excel_code",
            "excel_name",
            "account_type_code",
            "account_type_id",
            "account_type_name",
            "match",
        ],
        account_map_rows,
    )
    print(f"Mapped nonzero rows: {len(mapped)}")

    if unmapped:
        write_csv(
            out / "unmapped_accounts.csv",
            ["excel_row", "excel_code", "excel_name", "net", "reason"],
            [
                [
                    u["excel_row"],
                    u["code"],
                    u["name"],
                    "" if u["net"] is None else str(u["net"]),
                    u["reason"],
                ]
                for u in unmapped
            ],
        )
        clear_load_outputs(master_dir)
        print(f"Unmapped nonzero/invalid rows: {len(unmapped)}")
        for u in unmapped:
            print(f"  row {u['excel_row']}: {u['code']} {u['name']} net={u['net']} ({u['reason']})")
        print("Stopped without LOAD CSVs. Add COA codes or aliases, then re-run.")
        return 1

    unmapped_path = out / "unmapped_accounts.csv"
    if unmapped_path.exists():
        unmapped_path.unlink()

    total_debit = sum((r["debit"] for r in mapped), Decimal("0"))
    total_credit = sum((r["credit"] for r in mapped), Decimal("0"))
    imbalance = total_debit - total_credit
    print(f"Post-round debit={total_debit:.4f} credit={total_credit:.4f} delta={imbalance:.4f}")
    if abs(imbalance) > Decimal("0.01"):
        print(f"WARNING: debit/credit differ by {imbalance:.4f} (no rounding plug)")

    je_id = args.je_id_start
    item_id = args.item_id_start
    gl_id = args.gl_id_start

    je_rows = [
        [
            je_id,
            csv_cell(None),
            DATE,
            "journal_entry",
            REMARK,
            fmt_money(total_debit),
            fmt_money(total_credit),
            "approved",
            1,
            ADDED_BY,
            ADDED_ON,
            0,
        ]
    ]
    item_rows = []
    gl_rows = []
    for row in mapped:
        item_rows.append(
            [
                item_id,
                je_id,
                row["leaf"]["id"],
                fmt_money(row["debit"]),
                fmt_money(row["credit"]),
                REMARK,
                ADDED_BY,
                ADDED_ON,
                0,
            ]
        )
        gl_rows.append(
            [
                gl_id,
                csv_cell(None),
                "journal_entry",
                je_id,
                row["leaf"]["id"],
                DATE,
                fmt_money(row["debit"]),
                fmt_money(row["credit"]),
                REMARK,
                1,
                ADDED_BY,
                ADDED_ON,
                0,
            ]
        )
        item_id += 1
        gl_id += 1

    write_csv(master_dir / "journal_entry.csv", JE_COLS, je_rows)
    write_csv(master_dir / "journal_entry_item.csv", JE_ITEM_COLS, item_rows)
    write_csv(master_dir / "general_ledger.csv", GL_COLS, gl_rows)
    write_import_sql(master_dir, "journal_entry.csv", "journal_entry_item.csv", "general_ledger.csv")
    print(
        f"Wrote master JE {je_id} with {len(item_rows)} items "
        f"(ids {args.item_id_start}-{item_id - 1}) "
        f"and GL {args.gl_id_start}-{gl_id - 1}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
