#!/usr/bin/env python3
"""Build account_type.sql and FK remap SQL from the updated CPCI COA workbook."""

from __future__ import annotations

import re
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
XLSX = Path("/Users/myt_waren/Downloads/CPCI_Chart of Accounts for MYT updated.xlsx")
OLD_DUMP = Path(__file__).resolve().parent / "account_type_old.sql"
if not OLD_DUMP.exists():
    OLD_DUMP = ROOT / "account_type.sql"
OUT_DUMP = ROOT / "account_type.sql"
OUT_REMAP = Path(__file__).resolve().parent / "002_remap_fk_by_xero_code.sql"
SHEET_NAME = "COA V2 USE F submit 2"
ADDED_ON = "2026-09-01 00:00:00"
NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}

ST1_FALLBACK = {
    "CURRENT ASSETS": "100",
    "NONCURRENT ASSETS": "200",
    "CURRENT LIABILITY": "300",
    "NONCURRENT LIABILITIES": "400",
    "EQUITY": "500",
    "REVENUE": "600",
    "COST OF SALES": "700",
    "BRANCH OPERATING COSTS": "800.A",
    "GENERAL & ADMIN XPENSES": "800.B",
    "NONOPERATING EXPENSE": "900",
}

ST1_META = {
    "CURRENT ASSETS": ("current_asset", "debit", "balance_sheet"),
    "NONCURRENT ASSETS": ("non_current_asset", "debit", "balance_sheet"),
    "CURRENT LIABILITY": ("current_liability", "credit", "balance_sheet"),
    "NONCURRENT LIABILITIES": ("non_current_liability", "credit", "balance_sheet"),
    "EQUITY": ("equity", "credit", "balance_sheet"),
    "REVENUE": ("income", "credit", "profit_and_loss"),
    "COST OF SALES": ("cost_of_sales", "debit", "profit_and_loss"),
    "BRANCH OPERATING COSTS": ("expense", "debit", "profit_and_loss"),
    "GENERAL & ADMIN XPENSES": ("expense", "debit", "profit_and_loss"),
    "NONOPERATING EXPENSE": ("expense", "debit", "profit_and_loss"),
}

CREDIT_ST2 = {"INTEREST INCOME", "OTHER INCOME"}

CLINIC_DBS = [
    "mytemr_diagnostic_mobile",
    "mytemr_diagnostic",
    "mytemr_diagnostic_mactan",
    "mytemr_diagnostic_conso",
    "mytemr_diagnostic_mantlewood",
]

MASTER_TABLES = [
    ("journal_entry_item", "account_type_id"),
    ("general_ledger", "account_type_id"),
    ("item", "account_type_id"),
    ("item", "vat_account_type_id"),
    ("credit_memo", "debit_account_type_id"),
    ("credit_memo", "credit_account_type_id"),
    ("debit_memo", "debit_account_type_id"),
    ("debit_memo", "credit_account_type_id"),
    ("billing_payment", "account_type_id"),
    ("billing_payment_deposit", "account_type_id"),
    ("deposit", "account_type_id"),
]

CLINIC_TABLES = [
    ("general_ledger", "account_type_id"),
    ("journal_entry_item", "account_type_id"),
    ("receipt_note_item", "account_type_id"),
    ("receipt_note_additional_item", "account_type_id"),
    ("purchase_order_item", "account_type_id"),
    ("purchase_request_item", "account_type_id"),
    ("purchase_return_item", "account_type_id"),
    ("reimbursement_item", "account_type_id"),
    ("replenishment_item", "account_type_id"),
    ("liquidation_detail", "account_type_id"),
    ("cash_advance", "account_type_id"),
    ("petty_cash", "account_type_id"),
    ("change_fund", "account_type_id"),
    ("send_out_payment", "account_type_id"),
    ("accounts_payable_voucher_payment", "account_type_id"),
    ("direct_payment", "account_type_id"),
    ("direct_payment", "credit_account_type_id"),
    ("depreciation_entry_item", "debit_account_type_id"),
    ("depreciation_entry_item", "credit_account_type_id"),
    ("billing_payment", "account_type_id"),
]


def s(value) -> str:
    if value is None:
        return ""
    return str(value).strip()


def sql_str(value) -> str:
    if value is None or value == "":
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def sql_int_or_null(value) -> str:
    if value is None:
        return "NULL"
    return str(int(value))


def col_row(ref: str) -> tuple[int, int]:
    col = ""
    row = ""
    for ch in ref:
        if ch.isalpha():
            col += ch
        else:
            row += ch
    n = 0
    for ch in col:
        n = n * 26 + (ord(ch) - 64)
    return n, int(row)


def load_sheet_rows(path: Path, sheet_name: str) -> list[list]:
    with zipfile.ZipFile(path) as zf:
        ss: list[str] = []
        if "xl/sharedStrings.xml" in zf.namelist():
            root = ET.fromstring(zf.read("xl/sharedStrings.xml"))
            for si in root.findall("m:si", NS):
                ss.append("".join(t.text or "" for t in si.findall(".//m:t", NS)))

        wb = ET.fromstring(zf.read("xl/workbook.xml"))
        rels = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))
        rid_to_target = {rel.attrib.get("Id"): rel.attrib.get("Target") for rel in rels}
        target = None
        for sh in wb.findall("m:sheets/m:sheet", NS):
            if sh.attrib.get("name") != sheet_name:
                continue
            rid = sh.attrib.get("{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id")
            target = "xl/" + rid_to_target[rid].lstrip("/")
            if target.startswith("xl/xl/"):
                target = target[3:]
            break
        if not target:
            raise SystemExit(f"Sheet not found: {sheet_name}")

        root = ET.fromstring(zf.read(target))

        def cell_val(cell) -> str | None:
            kind = cell.attrib.get("t")
            node = cell.find("m:v", NS)
            if node is None or node.text is None:
                is_el = cell.find("m:is", NS)
                if is_el is not None:
                    return "".join(x.text or "" for x in is_el.findall(".//m:t", NS))
                return None
            if kind == "s":
                return ss[int(node.text)]
            return node.text

        grid: dict[tuple[int, int], str | None] = {}
        max_r = 0
        max_c = 0
        for cell in root.findall(".//m:c", NS):
            ref = cell.attrib.get("r")
            if not ref:
                continue
            cidx, ridx = col_row(ref)
            grid[(ridx, cidx)] = cell_val(cell)
            max_r = max(max_r, ridx)
            max_c = max(max_c, cidx)

    rows = []
    for ridx in range(2, max_r + 1):
        rec = [grid.get((ridx, cidx)) for cidx in range(1, 13)]
        if not any(s(v) for v in rec):
            continue
        rows.append(rec)
    return rows


def parse_sql_tuple(text: str) -> list[str | None]:
    values: list[str | None] = []
    i = 0
    n = len(text)
    while i < n:
        while i < n and text[i] in " \t\n\r,":
            i += 1
        if i >= n:
            break
        if text.startswith("NULL", i):
            values.append(None)
            i += 4
            continue
        if text[i] == "'":
            i += 1
            buf = []
            while i < n:
                if text[i] == "\\" and i + 1 < n:
                    buf.append(text[i + 1])
                    i += 2
                    continue
                if text[i] == "'":
                    if i + 1 < n and text[i + 1] == "'":
                        buf.append("'")
                        i += 2
                        continue
                    i += 1
                    break
                buf.append(text[i])
                i += 1
            values.append("".join(buf))
            continue
        j = i
        while j < n and text[j] not in ",)":
            j += 1
        values.append(text[i:j].strip())
        i = j
    return values


def iter_sql_tuples(chunk: str):
    i = chunk.find("VALUES")
    if i < 0:
        return
    i = chunk.find("(", i)
    n = len(chunk)
    while i < n:
        if chunk[i] != "(":
            if chunk[i] == ";":
                return
            i += 1
            continue
        depth = 0
        in_str = False
        escape = False
        j = i
        while j < n:
            ch = chunk[j]
            if in_str:
                if escape:
                    escape = False
                elif ch == "\\":
                    escape = True
                elif ch == "'":
                    in_str = False
                j += 1
                continue
            if ch == "'":
                in_str = True
            elif ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
                if depth == 0:
                    yield chunk[i + 1 : j]
                    i = j + 1
                    break
            j += 1
        else:
            return


def load_old_leaf_ids(path: Path) -> dict[int, str]:
    text = path.read_text(encoding="utf-8")
    id_to_parent: dict[int, int | None] = {}
    id_to_code: dict[int, str] = {}
    pos = 0
    while True:
        start = text.find("INSERT INTO `account_type`", pos)
        if start < 0:
            break
        end = text.find("INSERT INTO `account_type`", start + 1)
        chunk = text[start:] if end < 0 else text[start:end]
        pos = start + 1
        for inner in iter_sql_tuples(chunk):
            vals = parse_sql_tuple(inner)
            if len(vals) < 8:
                continue
            try:
                row_id = int(vals[0])
            except (TypeError, ValueError):
                continue
            parent = None if vals[1] is None else int(vals[1])
            code = s(vals[7])
            id_to_parent[row_id] = parent
            id_to_code[row_id] = code

    leaf_code_by_id: dict[int, str] = {}
    for row_id, parent in id_to_parent.items():
        leaf_id = row_id if parent is None else parent
        code = id_to_code.get(leaf_id, "")
        if code:
            leaf_code_by_id[row_id] = code
    return leaf_code_by_id


def row_meta(st1: str, st2: str, typ: str) -> tuple[str, str, str]:
    category, nature, fs = ST1_META.get(st1, ("expense", "debit", "profit_and_loss"))
    if typ.upper().startswith("BALANCE"):
        fs = "balance_sheet"
    elif typ.upper().startswith("PROFIT"):
        fs = "profit_and_loss"
    if st1 == "NONOPERATING EXPENSE" and st2.upper() in CREDIT_ST2:
        nature = "credit"
        category = "income"
    return category, nature, fs


def build_accounts(rows: list[list]) -> tuple[list[tuple], dict[str, int]]:
    accounts = []
    next_id = 1
    new_leaf_by_code: dict[str, int] = {}
    skipped = []

    for rec in rows:
        group = s(rec[0])
        sub = s(rec[1])
        xero = s(rec[2])
        name = s(rec[3])
        typ = s(rec[4])
        cifi = s(rec[6])
        st1 = s(rec[7])
        st2 = s(rec[8])
        st3 = s(rec[9])
        cifi_st2 = s(rec[10])
        cifi_st1 = s(rec[11])

        if not xero:
            xero = sub
        if not name or not xero:
            skipped.append((xero, name))
            continue
        if cifi and not re.match(r"^[0-9]", cifi):
            cifi = ""

        category, nature, fs = row_meta(st1, st2, typ)
        st1_code = cifi_st1 or ST1_FALLBACK.get(st1, group)
        st2_code = cifi_st2 or group
        st3_code = cifi or sub or "790"

        parent_id = next_id
        next_id += 1
        new_leaf_by_code[xero] = parent_id
        accounts.append((parent_id, None, nature, name, category, fs, group, xero, None))
        accounts.append((next_id, parent_id, nature, st1, category, fs, group, st1_code, "1"))
        next_id += 1
        accounts.append((next_id, parent_id, nature, st2, category, fs, group, st2_code, "2"))
        next_id += 1
        accounts.append((next_id, parent_id, nature, st3, category, fs, group, st3_code, "3"))
        next_id += 1

    if skipped:
        print("skipped rows without Xero CODE:", skipped)
    return accounts, new_leaf_by_code


def write_dump(accounts: list[tuple], out_path: Path) -> None:
    next_ai = max(a[0] for a in accounts) + 1
    lines = [
        "-- Chart of accounts from CPCI_Chart of Accounts for MYT updated.xlsx",
        "-- Sheet: COA V2 USE F submit 2",
        "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";",
        "START TRANSACTION;",
        "SET time_zone = \"+00:00\";",
        "",
        "DROP TABLE IF EXISTS `account_type`;",
        "CREATE TABLE `account_type` (",
        "  `id` int(11) NOT NULL,",
        "  `parent_id` int(11) DEFAULT NULL,",
        "  `nature` enum('debit','credit') DEFAULT NULL,",
        "  `name` varchar(250) NOT NULL,",
        "  `category` varchar(250) DEFAULT NULL,",
        "  `financial_statement` enum('profit_and_loss','balance_sheet') DEFAULT NULL,",
        "  `status` enum('active','inactive') NOT NULL DEFAULT 'active',",
        "  `group_code` varchar(255) DEFAULT NULL,",
        "  `code` varchar(255) DEFAULT NULL,",
        "  `no` varchar(255) DEFAULT NULL,",
        "  `tax_code` varchar(255) DEFAULT NULL,",
        "  `description` text DEFAULT NULL,",
        "  `is_dashboard` int(11) NOT NULL DEFAULT 0,",
        "  `is_expense_claims` int(11) NOT NULL DEFAULT 0,",
        "  `is_enable_payments` int(11) NOT NULL DEFAULT 0,",
        "  `added_by` int(11) NOT NULL,",
        "  `added_on` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),",
        "  `updated_by` int(11) DEFAULT NULL,",
        "  `updated_on` timestamp NULL DEFAULT NULL,",
        "  `is_deleted` int(11) NOT NULL DEFAULT 0",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
        "INSERT INTO `account_type` (`id`, `parent_id`, `nature`, `name`, `category`, `financial_statement`, `status`, `group_code`, `code`, `no`, `tax_code`, `description`, `is_dashboard`, `is_expense_claims`, `is_enable_payments`, `added_by`, `added_on`, `updated_by`, `updated_on`, `is_deleted`) VALUES",
    ]
    value_rows = []
    for row_id, parent_id, nature, name, category, fs, group, code, no in accounts:
        tax = "NULL" if parent_id is not None else "'no_applicable_0'"
        value_rows.append(
            "("
            + ", ".join(
                [
                    str(row_id),
                    sql_int_or_null(parent_id),
                    sql_str(nature),
                    sql_str(name),
                    sql_str(category),
                    sql_str(fs),
                    "'active'",
                    sql_str(group),
                    sql_str(code),
                    sql_str(no),
                    tax,
                    "NULL",
                    "0",
                    "0",
                    "0",
                    "108",
                    sql_str(ADDED_ON),
                    "NULL",
                    "NULL",
                    "0",
                ]
            )
            + ")"
        )
    lines.append(",\n".join(value_rows) + ";")
    lines.extend(
        [
            "",
            "ALTER TABLE `account_type`",
            "  ADD PRIMARY KEY (`id`);",
            "",
            "ALTER TABLE `account_type`",
            f"  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT={next_ai};",
            "COMMIT;",
            "",
        ]
    )
    out_path.write_text("\n".join(lines), encoding="utf-8")


def write_remap(old_id_to_code: dict[int, str], new_leaf_by_code: dict[str, int], out_path: Path) -> None:
    pairs = []
    missing = []
    for old_id, code in sorted(old_id_to_code.items()):
        new_id = new_leaf_by_code.get(code)
        if new_id is None:
            missing.append((old_id, code))
            continue
        if old_id != new_id:
            pairs.append((old_id, new_id, code))

    out = [
        "-- Remap posted account_type_id values after replacing account_type.sql.",
        "-- Map old leaf/child ids to the new leaf id for the same Xero CODE.",
        "-- Run on mytemr_master after loading the new account_type dump, then on each clinic DB.",
        "START TRANSACTION;",
        "",
        "DROP TEMPORARY TABLE IF EXISTS account_type_id_map;",
        "CREATE TEMPORARY TABLE account_type_id_map (",
        "  old_id int(11) NOT NULL,",
        "  new_id int(11) NOT NULL,",
        "  PRIMARY KEY (old_id)",
        ");",
        "",
        "INSERT INTO account_type_id_map (old_id, new_id) VALUES",
    ]
    if pairs:
        out.append(",\n".join(f"({old_id}, {new_id})" for old_id, new_id, _code in pairs) + ";")
    else:
        out.append("(0, 0); -- no id changes")

    def updates(tables: list[tuple[str, str]], db_label: str) -> list[str]:
        lines = ["", f"-- {db_label}"]
        for table, col in tables:
            lines.extend(
                [
                    f"UPDATE `{table}` AS t",
                    "INNER JOIN account_type_id_map AS m ON m.old_id = t.`" + col + "`",
                    f"SET t.`{col}` = m.new_id",
                    f"WHERE t.`{col}` IS NOT NULL;",
                ]
            )
        return lines

    out.extend(updates(MASTER_TABLES, "mytemr_master tables (run while connected to mytemr_master)"))
    out.extend(
        [
            "",
            "-- Repeat the clinic block on each of:",
            "--   " + ", ".join(CLINIC_DBS),
        ]
    )
    out.extend(updates(CLINIC_TABLES, "clinic tables"))
    out.extend(
        [
            "",
            "COMMIT;",
            "",
        ]
    )
    if missing:
        out.append("-- Old ids with no matching Xero CODE in the new COA:")
        for old_id, code in missing:
            out.append(f"--   {old_id} -> {code or '(empty code)'}")
        out.append("")

    out_path.write_text("\n".join(out), encoding="utf-8")
    print(f"remap pairs={len(pairs)} missing={len(missing)}")


def main() -> None:
    rows = load_sheet_rows(XLSX, SHEET_NAME)
    accounts, new_leaf_by_code = build_accounts(rows)
    write_dump(accounts, OUT_DUMP)
    old_id_to_code = load_old_leaf_ids(OLD_DUMP)
    # OLD_DUMP was overwritten; parse from the just-written? No — we need the previous dump.
    # Caller must pass the previous dump. If we already overwrote, remap uses
    # the in-memory old parse taken before write. Re-read is wrong after overwrite.
    write_remap(old_id_to_code, new_leaf_by_code, OUT_REMAP)
    print(f"wrote {OUT_DUMP} rows={len(accounts)} leaves={len(new_leaf_by_code)}")
    print(f"wrote {OUT_REMAP}")


if __name__ == "__main__":
    # Parse old dump before overwriting.
    old_id_to_code = load_old_leaf_ids(OLD_DUMP)
    rows = load_sheet_rows(XLSX, SHEET_NAME)
    accounts, new_leaf_by_code = build_accounts(rows)
    write_dump(accounts, OUT_DUMP)
    write_remap(old_id_to_code, new_leaf_by_code, OUT_REMAP)
    print(f"wrote {OUT_DUMP} rows={len(accounts)} leaves={len(new_leaf_by_code)}")
    print(f"wrote {OUT_REMAP}")
