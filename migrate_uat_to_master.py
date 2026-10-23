#!/usr/bin/env python3
"""Migrate mytemr_master_uat.sql into mytemr_master.sql with ID remapping."""

from __future__ import annotations

import re
import sys
from pathlib import Path
from typing import Dict, List, Optional, Tuple

ROOT = Path(__file__).resolve().parent
UAT_PATH = ROOT / "mytemr_master_uat.sql"
MASTER_PATH = ROOT / "mytemr_master.sql"

# In-dump FK column -> parent table
FK_REMAP: Dict[str, str] = {
    "request_id": "request",
    "costing_id": "costing",
    "quotation_id": "quotation",
    "endorsement_id": "endorsement",
    "costing_service_id": "costing_service",
    "quotation_service_id": "quotation_service",
    "endorsement_service_id": "endorsement_service",
    "costing_manpower_cost_id": "costing_manpower_cost",
}

HISTORY_SOURCE_PARENT = {
    "request_history": "request",
    "costing_history": "costing",
    "quotation_history": "quotation",
    "endorsement_history": "endorsement",
}

# Parents before children for append order
INSERT_ORDER = [
    "request",
    "request_consultation",
    "request_detail",
    "request_history",
    "request_service",
    "request_shift",
    "costing",
    "costing_consulta",
    "costing_consultation",
    "costing_detail",
    "costing_discount",
    "costing_history",
    "costing_manpower",
    "costing_manpower_cost",
    "costing_manpower_cost_premium_pay",
    "costing_mobilization",
    "costing_service",
    "costing_service_package_detail",
    "costing_shift",
    "quotation",
    "quotation_attachment",
    "quotation_consultation",
    "quotation_detail",
    "quotation_history",
    "quotation_service",
    "quotation_service_package_detail",
    "quotation_shift",
    "quotation_signed_moa",
    "endorsement",
    "endorsement_attachment",
    "endorsement_consulta",
    "endorsement_consultation",
    "endorsement_detail",
    "endorsement_discount",
    "endorsement_employer",
    "endorsement_history",
    "endorsement_patient",
    "endorsement_revision",
    "endorsement_service",
    "endorsement_service_package_detail",
    "endorsement_shift",
    "endorsement_signed_moa",
]


def split_sql_values(row: str) -> List[str]:
    """Split a single SQL VALUES tuple body into field tokens."""
    parts: List[str] = []
    cur: List[str] = []
    in_q = False
    i = 0
    while i < len(row):
        ch = row[i]
        if ch == "'" and not in_q:
            in_q = True
            cur.append(ch)
            i += 1
            continue
        if ch == "'" and in_q:
            if i + 1 < len(row) and row[i + 1] == "'":
                cur.append("''")
                i += 2
                continue
            in_q = False
            cur.append(ch)
            i += 1
            continue
        if ch == "," and not in_q:
            parts.append("".join(cur).strip())
            cur = []
            i += 1
            continue
        cur.append(ch)
        i += 1
    if cur or not parts:
        parts.append("".join(cur).strip())
    return parts


def split_insert_rows(values_block: str) -> List[str]:
    """Split INSERT VALUES block into raw tuple bodies (no surrounding parens)."""
    rows: List[str] = []
    cur: List[str] = []
    in_q = False
    depth = 0
    i = 0
    s = values_block.strip()
    while i < len(s):
        ch = s[i]
        if ch == "'" and not in_q:
            in_q = True
            if depth > 0:
                cur.append(ch)
            i += 1
            continue
        if ch == "'" and in_q:
            if i + 1 < len(s) and s[i + 1] == "'":
                if depth > 0:
                    cur.append("''")
                i += 2
                continue
            in_q = False
            if depth > 0:
                cur.append(ch)
            i += 1
            continue
        if not in_q:
            if ch == "(":
                depth += 1
                if depth == 1:
                    cur = []
                    i += 1
                    continue
            elif ch == ")":
                depth -= 1
                if depth == 0:
                    rows.append("".join(cur))
                    cur = []
                    i += 1
                    continue
        if depth > 0:
            cur.append(ch)
        i += 1
    return rows


def parse_sql_int(token: str) -> Optional[int]:
    token = token.strip()
    if token.upper() == "NULL" or token == "":
        return None
    if token.startswith("'") and token.endswith("'"):
        inner = token[1:-1]
        if inner.isdigit() or (inner.startswith("-") and inner[1:].isdigit()):
            return int(inner)
        return None
    if re.fullmatch(r"-?\d+", token):
        return int(token)
    return None


def format_sql_int(value: Optional[int]) -> str:
    if value is None:
        return "NULL"
    return str(value)


def get_auto_increments(content: str) -> Dict[str, int]:
    """Map table -> AUTO_INCREMENT from ALTER TABLE blocks."""
    result: Dict[str, int] = {}
    current = None
    for line in content.splitlines():
        m = re.search(r"ALTER TABLE `([^`]+)`", line)
        if m:
            current = m.group(1)
        m2 = re.search(r"AUTO_INCREMENT=(\d+)", line)
        if m2 and current:
            result[current] = int(m2.group(1))
            current = None
    return result


def get_id_type(create_body: str) -> str:
    m = re.search(r"`id`\s+(\w+(?:\([^)]+\))?)", create_body)
    if not m:
        return "int(11)"
    return m.group(1)


def parse_tables(content: str) -> Dict[str, dict]:
    """Parse CREATE/INSERT info per table from a phpMyAdmin dump."""
    tables: Dict[str, dict] = {}

    for m in re.finditer(
        r"CREATE TABLE `([^`]+)` \((.*?)\) ENGINE=([^;]+);",
        content,
        re.S,
    ):
        name = m.group(1)
        body = m.group(2)
        engine = m.group(3)
        cols = re.findall(r"^  `([^`]+)`", body, re.M)
        tables[name] = {
            "create_body": body,
            "engine": engine,
            "columns": cols,
            "id_type": get_id_type(body),
            "inserts": [],
            "create_span": (m.start(), m.end()),
        }

    for m in re.finditer(
        r"INSERT INTO `([^`]+)` \(([^)]+)\) VALUES\s*(.*?);",
        content,
        re.S,
    ):
        name = m.group(1)
        cols = [c.strip().strip("`") for c in m.group(2).split(",")]
        raw_rows = split_insert_rows(m.group(3))
        parsed_rows = [split_sql_values(r) for r in raw_rows]
        if name not in tables:
            tables[name] = {
                "create_body": "",
                "engine": "",
                "columns": cols,
                "id_type": "int(11)",
                "inserts": [],
                "create_span": None,
            }
        tables[name]["inserts"].append((cols, parsed_rows))

    return tables


def all_rows(table_info: dict) -> Tuple[List[str], List[List[str]]]:
    """Flatten inserts; require consistent column lists (use first)."""
    if not table_info["inserts"]:
        return table_info.get("columns", []), []
    cols0 = table_info["inserts"][0][0]
    rows: List[List[str]] = []
    for cols, batch in table_info["inserts"]:
        if cols != cols0:
            idx = {c: i for i, c in enumerate(cols)}
            for r in batch:
                aligned = []
                for c in cols0:
                    aligned.append(r[idx[c]] if c in idx else "NULL")
                rows.append(aligned)
        else:
            rows.extend(batch)
    return cols0, rows


def collect_ids(cols: List[str], rows: List[List[str]]) -> List[int]:
    if "id" not in cols:
        return []
    idx = cols.index("id")
    ids = []
    for r in rows:
        v = parse_sql_int(r[idx])
        if v is not None:
            ids.append(v)
    return ids


def build_id_maps(
    uat_tables: Dict[str, dict], master_tables: Dict[str, dict], master_ai: Dict[str, int]
) -> Dict[str, Dict[int, int]]:
    maps: Dict[str, Dict[int, int]] = {}
    for name, info in uat_tables.items():
        cols, rows = all_rows(info)
        uat_ids = collect_ids(cols, rows)
        m_cols, m_rows = all_rows(master_tables.get(name, {"inserts": [], "columns": []}))
        master_ids = collect_ids(m_cols, m_rows)
        max_master = max(master_ids) if master_ids else 0
        ai = master_ai.get(name)
        if ai is None:
            base = max_master + 1 if max_master else 1
        else:
            base = max(ai, max_master + 1)
        offset = base - 1  # master_id = uat_id + offset
        id_map = {uid: uid + offset for uid in uat_ids}
        overlap = set(id_map.values()) & set(master_ids)
        if overlap:
            raise SystemExit(f"PK collision on {name}: {sorted(list(overlap))[:10]}")
        maps[name] = id_map
        print(
            f"  map {name:40} uat_rows={len(uat_ids):4} offset={offset:4} "
            f"new_range={min(id_map.values()) if id_map else '-'}..{max(id_map.values()) if id_map else '-'} "
            f"master_ai_was={ai}"
        )
    return maps


def remap_row(
    table: str,
    cols: List[str],
    row: List[str],
    id_maps: Dict[str, Dict[int, int]],
    use_master_pk: bool,
) -> List[str]:
    out = list(row)
    for i, col in enumerate(cols):
        if col == "id":
            old = parse_sql_int(row[i])
            if old is None:
                continue
            if use_master_pk:
                out[i] = format_sql_int(id_maps[table][old])
            continue
        if col == "source_id" and table in HISTORY_SOURCE_PARENT:
            parent = HISTORY_SOURCE_PARENT[table]
            old = parse_sql_int(row[i])
            if old is None:
                continue
            if old not in id_maps[parent]:
                raise SystemExit(f"{table}.source_id={old} missing in {parent} map")
            out[i] = format_sql_int(id_maps[parent][old])
            continue
        if col in FK_REMAP:
            parent = FK_REMAP[col]
            old = parse_sql_int(row[i])
            if old is None:
                continue
            if parent not in id_maps:
                raise SystemExit(f"Missing parent map {parent} for {table}.{col}")
            if old not in id_maps[parent]:
                raise SystemExit(f"{table}.{col}={old} not found in {parent} id map")
            out[i] = format_sql_int(id_maps[parent][old])
    return out


def sql_row(values: List[str]) -> str:
    return "(" + ", ".join(values) + ")"


def chunked_insert(table: str, cols: List[str], rows: List[List[str]], chunk: int = 200) -> str:
    if not rows:
        return ""
    col_list = ", ".join(f"`{c}`" for c in cols)
    parts = []
    for i in range(0, len(rows), chunk):
        batch = rows[i : i + chunk]
        values = ",\n".join(sql_row(r) for r in batch)
        parts.append(f"INSERT INTO `{table}` ({col_list}) VALUES\n{values};")
    return "\n\n".join(parts)


def rename_table_refs(content: str, tables: List[str]) -> str:
    """Rename `table` -> `table_uat` for known tables (word-boundary via backticks)."""
    out = content
    for t in sorted(tables, key=len, reverse=True):
        out = out.replace(f"`{t}`", f"`{t}_uat`")
    return out


def rename_trigger_refs(content: str, tables: List[str]) -> str:
    """Rename backticked table refs plus unquoted INSERT INTO target tables."""
    out = rename_table_refs(content, tables)
    for t in sorted(tables, key=len, reverse=True):
        out = re.sub(
            rf"\bINSERT INTO {re.escape(t)}\b",
            f"INSERT INTO {t}_uat",
            out,
            flags=re.IGNORECASE,
        )
    return out


def extract_triggers(original: str, table: str) -> str:
    """Extract phpMyAdmin trigger block for a table, if present."""
    marker = f"--\n-- Triggers `{table}`\n--"
    start = original.find(marker)
    if start == -1:
        return ""
    rest = original[start:]
    m = re.search(r"DELIMITER ;\n", rest)
    if not m:
        return ""
    return rest[: m.end()].rstrip() + "\n\n"


def add_master_id_to_create(create_body: str, id_type: str) -> str:
    """Insert master_id column definition after last data column."""
    lines = create_body.splitlines(True)
    last_col_idx = None
    for i, line in enumerate(lines):
        if re.match(r"^  `[a-zA-Z0-9_]+`", line):
            last_col_idx = i
    if last_col_idx is None:
        raise SystemExit("Could not find columns in CREATE body")

    # Keys/constraints after columns mean master_id needs a trailing comma.
    has_trailing_keys = any(
        re.match(r"^\s*(PRIMARY KEY|KEY|UNIQUE|CONSTRAINT)\b", line)
        for line in lines[last_col_idx + 1 :]
    )

    prev = lines[last_col_idx]
    if not prev.rstrip().endswith(","):
        if prev.endswith("\n"):
            lines[last_col_idx] = prev.rstrip("\n").rstrip() + ",\n"
        else:
            lines[last_col_idx] = prev.rstrip() + ","

    if has_trailing_keys:
        master_line = f"  `master_id` {id_type} DEFAULT NULL,\n"
    else:
        master_line = f"  `master_id` {id_type} DEFAULT NULL\n"
    lines.insert(last_col_idx + 1, master_line)
    return "".join(lines)


def rebuild_uat_file(
    original: str,
    uat_tables: Dict[str, dict],
    id_maps: Dict[str, Dict[int, int]],
) -> str:
    """Produce rewritten UAT dump with *_uat names and master_id filled."""
    table_names = list(uat_tables.keys())

    first_create = original.find("CREATE TABLE")
    header = original[:first_create]

    commit_idx = original.rfind("COMMIT;")
    footer = original[commit_idx + len("COMMIT;") :] if commit_idx != -1 else "\n"

    parts = [header]

    create_order = [m.group(1) for m in re.finditer(r"CREATE TABLE `([^`]+)`", original)]

    for name in create_order:
        info = uat_tables[name]
        body = add_master_id_to_create(info["create_body"], info["id_type"])
        uat_name = f"{name}_uat"
        parts.append(f"--\n-- Table structure for table `{uat_name}`\n--\n\n")
        parts.append(f"CREATE TABLE `{uat_name}` ({body}) ENGINE={info['engine']};\n\n")

        cols, rows = all_rows(info)
        if rows:
            new_cols = cols + ["master_id"]
            id_idx = cols.index("id")
            new_rows = []
            for r in rows:
                old_id = parse_sql_int(r[id_idx])
                mid = id_maps[name][old_id] if old_id is not None else None
                new_rows.append(r + [format_sql_int(mid)])
            parts.append(f"--\n-- Dumping data for table `{uat_name}`\n--\n\n")
            parts.append(chunked_insert(uat_name, new_cols, new_rows))
            parts.append("\n\n")

        triggers = extract_triggers(original, name)
        if triggers:
            parts.append(rename_trigger_refs(triggers, table_names))

    idx_start = original.find("--\n-- Indexes for dumped tables")
    if idx_start == -1:
        idx_start = original.find("\n--\n-- Indexes for table")
    ai_start = original.find("--\n-- AUTO_INCREMENT for dumped tables")
    if idx_start != -1 and ai_start != -1:
        index_section = original[idx_start:ai_start]
        index_section = rename_table_refs(index_section, table_names)
        parts.append(index_section)

        ai_section = original[ai_start:commit_idx] if commit_idx != -1 else original[ai_start:]
        ai_section = rename_table_refs(ai_section, table_names)
        parts.append(ai_section)

    parts.append("COMMIT;")
    parts.append(footer)
    return "".join(parts)


def update_master_file(
    original: str,
    uat_tables: Dict[str, dict],
    master_tables: Dict[str, dict],
    id_maps: Dict[str, Dict[int, int]],
    master_ai: Dict[str, int],
) -> str:
    """Append remapped INSERTs and bump AUTO_INCREMENT values."""
    migration_blocks = [
        "\n--\n-- UAT migration: remapped inserts from mytemr_master_uat\n--\n"
    ]
    new_ai: Dict[str, int] = dict(master_ai)

    for name in INSERT_ORDER:
        if name not in uat_tables:
            continue
        info = uat_tables[name]
        cols, rows = all_rows(info)
        if not rows:
            continue
        remapped = [remap_row(name, cols, r, id_maps, use_master_pk=True) for r in rows]
        migration_blocks.append(f"\n-- Migrated data for `{name}` ({len(remapped)} rows)\n")
        migration_blocks.append(chunked_insert(name, cols, remapped))
        migration_blocks.append("\n")

        new_ids = [parse_sql_int(r[cols.index("id")]) for r in remapped]
        new_ids = [i for i in new_ids if i is not None]
        m_cols, m_rows = all_rows(master_tables.get(name, {"inserts": [], "columns": []}))
        old_ids = collect_ids(m_cols, m_rows)
        max_id = max(new_ids + old_ids) if (new_ids or old_ids) else 0
        new_ai[name] = max_id + 1
        print(f"  insert {name:40} rows={len(remapped):4} new_AI={new_ai[name]}")

    for name, ai in master_ai.items():
        if name not in new_ai:
            new_ai[name] = ai

    migration_sql = "".join(migration_blocks)

    ai_marker = "--\n-- AUTO_INCREMENT for dumped tables"
    commit_idx = original.rfind("COMMIT;")
    if ai_marker in original:
        ai_pos = original.find(ai_marker)
        before = original[:ai_pos]
        ai_and_after = original[ai_pos:commit_idx] if commit_idx != -1 else original[ai_pos:]
        footer = original[commit_idx:] if commit_idx != -1 else "\nCOMMIT;\n"
    else:
        before = original[:commit_idx] if commit_idx != -1 else original
        ai_and_after = ""
        footer = original[commit_idx:] if commit_idx != -1 else "\nCOMMIT;\n"

    def bump_ai_section(section: str) -> str:
        current = None
        lines = section.splitlines(True)
        out = []
        for line in lines:
            m = re.search(r"ALTER TABLE `([^`]+)`", line)
            if m:
                current = m.group(1)
            if current and "AUTO_INCREMENT=" in line and current in new_ai:
                line = re.sub(
                    r"AUTO_INCREMENT=\d+",
                    f"AUTO_INCREMENT={new_ai[current]}",
                    line,
                )
                current = None
            elif (
                current
                and "AUTO_INCREMENT" in line
                and "AUTO_INCREMENT=" not in line
                and current in new_ai
            ):
                if line.rstrip().endswith(";"):
                    line = line.rstrip()[:-1] + f", AUTO_INCREMENT={new_ai[current]};\n"
                current = None
            out.append(line)
        return "".join(out)

    ai_section = bump_ai_section(ai_and_after)

    for name, ai_val in new_ai.items():
        if name not in uat_tables:
            continue
        cols, rows = all_rows(uat_tables[name])
        if not rows:
            continue
        pattern = rf"ALTER TABLE `{re.escape(name)}`\s*\n\s*MODIFY `id`[^;]+;"
        m = re.search(pattern, ai_section)
        if m:
            block = m.group(0)
            if f"AUTO_INCREMENT={ai_val}" not in block:
                if "AUTO_INCREMENT=" in block:
                    block2 = re.sub(r"AUTO_INCREMENT=\d+", f"AUTO_INCREMENT={ai_val}", block)
                else:
                    block2 = block.rstrip(";") + f", AUTO_INCREMENT={ai_val};"
                ai_section = ai_section[: m.start()] + block2 + ai_section[m.end() :]
        else:
            id_type = uat_tables[name]["id_type"]
            ai_section += (
                f"\n--\n-- AUTO_INCREMENT for table `{name}`\n--\n"
                f"ALTER TABLE `{name}`\n"
                f"  MODIFY `id` {id_type} NOT NULL AUTO_INCREMENT, AUTO_INCREMENT={ai_val};\n"
            )

    return before + migration_sql + "\n" + ai_section + footer


def main() -> None:
    print("Reading dumps...")
    uat_sql = UAT_PATH.read_text()
    master_sql = MASTER_PATH.read_text()

    if "UAT migration:" in master_sql or "`costing_uat`" in uat_sql:
        raise SystemExit(
            "Dumps already look migrated. Restore from .sql.bak before re-running."
        )

    print("Parsing UAT...")
    uat_tables = parse_tables(uat_sql)
    print("Parsing MASTER...")
    master_tables = parse_tables(master_sql)
    master_ai = get_auto_increments(master_sql)

    print(f"UAT tables: {len(uat_tables)}, MASTER tables: {len(master_tables)}")
    if set(uat_tables) != set(master_tables):
        only_uat = set(uat_tables) - set(master_tables)
        only_m = set(master_tables) - set(uat_tables)
        print("WARNING table mismatch", only_uat, only_m)

    print("Building ID maps...")
    id_maps = build_id_maps(uat_tables, master_tables, master_ai)

    uat_bak = UAT_PATH.with_suffix(".sql.bak")
    master_bak = MASTER_PATH.with_suffix(".sql.bak")
    if not uat_bak.exists():
        uat_bak.write_text(uat_sql)
        print(f"Backup: {uat_bak}")
    else:
        print(f"Backup exists: {uat_bak}")
    if not master_bak.exists():
        master_bak.write_text(master_sql)
        print(f"Backup: {master_bak}")
    else:
        print(f"Backup exists: {master_bak}")

    print("Rewriting UAT file...")
    new_uat = rebuild_uat_file(uat_sql, uat_tables, id_maps)
    UAT_PATH.write_text(new_uat)
    print(f"Wrote {UAT_PATH} ({len(new_uat)} bytes)")

    print("Updating MASTER file...")
    new_master = update_master_file(master_sql, uat_tables, master_tables, id_maps, master_ai)
    MASTER_PATH.write_text(new_master)
    print(f"Wrote {MASTER_PATH} ({len(new_master)} bytes)")

    print("\n=== Verification ===")
    v_uat = parse_tables(new_uat)
    v_master = parse_tables(MASTER_PATH.read_text())
    errors = 0
    for name in INSERT_ORDER:
        u_info = uat_tables[name]
        u_cols, u_rows = all_rows(u_info)
        uat_name = f"{name}_uat"
        if uat_name not in v_uat:
            print(f"FAIL missing {uat_name} in UAT file")
            errors += 1
            continue
        if "master_id" not in v_uat[uat_name]["columns"]:
            print(f"FAIL {uat_name} missing master_id column in CREATE")
            errors += 1
        vu_cols, vu_rows = all_rows(v_uat[uat_name])
        if len(vu_rows) != len(u_rows):
            print(f"FAIL {uat_name} row count {len(vu_rows)} != {len(u_rows)}")
            errors += 1

        m_cols, m_rows_orig = all_rows(master_tables[name])
        vm_cols, vm_rows = all_rows(v_master[name])
        expected = len(m_rows_orig) + len(u_rows)
        if len(vm_rows) != expected:
            print(
                f"FAIL {name} master rows {len(vm_rows)} != {expected} "
                f"(orig {len(m_rows_orig)} + uat {len(u_rows)})"
            )
            errors += 1
        else:
            print(
                f"OK   {name:40} master_rows={len(vm_rows)} "
                f"(was {len(m_rows_orig)} +{len(u_rows)})"
            )

        ids = collect_ids(vm_cols, vm_rows)
        if len(ids) != len(set(ids)):
            print(f"FAIL {name} duplicate ids in master")
            errors += 1

    for name in INSERT_ORDER:
        cols, rows = all_rows(uat_tables[name])
        if not rows:
            continue
        for r in rows:
            try:
                remap_row(name, cols, r, id_maps, use_master_pk=True)
            except SystemExit as e:
                print(f"FAIL FK {e}")
                errors += 1

    if errors:
        print(f"\nCompleted with {errors} error(s)")
        sys.exit(1)
    print("\nAll checks passed.")


if __name__ == "__main__":
    main()
