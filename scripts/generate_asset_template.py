#!/usr/bin/env python3
"""Generate docs/templates/asset_migration_template.xlsx

Mirrors scripts/generate_asset_template.php for environments without PhpSpreadsheet.
"""

from pathlib import Path

from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter
from openpyxl.worksheet.datavalidation import DataValidation

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "docs" / "templates" / "asset_migration_template.xlsx"

HEADER_FILL = PatternFill("solid", fgColor="D9E2F3")
REQUIRED_FILL = PatternFill("solid", fgColor="FFF2CC")
SAMPLE_FILL = PatternFill("solid", fgColor="F2F2F2")
HEADER_FONT = Font(bold=True)
SAMPLE_FONT = Font(italic=True, color="666666")
TITLE_FONT = Font(bold=True, size=16)

ASSET_HEADERS = [
    "asset_key",
    "name",
    "cost_center_name",
    "cost_center_id",
    "asset_group_name",
    "asset_group_id",
    "item_name",
    "item_code",
    "item_id",
    "supplier_name",
    "supplier_id",
    "useful_life_years",
    "end_user_location",
    "cost",
    "as_of",
    "each_period",
    "accumulated",
    "net_value",
    "consumption_unit",
]
ASSET_REQUIRED = {"asset_key", "name", "cost_center_name", "cost_center_id"}

REAGENT_HEADERS = ["asset_key", "item_name", "item_code", "item_id"]
LAB_TEST_HEADERS = ["asset_key", "lab_test_key", "lab_test_name", "lab_test_id"]
MACHINE_HEADERS = [
    "asset_key",
    "lab_test_key",
    "lab_test_name",
    "item_name",
    "item_id",
    "qty",
    "unit_name",
    "unit",
]


def style_header(sheet, headers, required):
    for idx, header in enumerate(headers, start=1):
        cell = sheet.cell(1, idx, header)
        cell.font = HEADER_FONT
        cell.fill = REQUIRED_FILL if header in required else HEADER_FILL
        sheet.column_dimensions[get_column_letter(idx)].width = max(16, len(header) + 4)
    sheet.freeze_panes = "A2"
    sheet.auto_filter.ref = f"A1:{get_column_letter(len(headers))}1"


def write_rows(sheet, headers, rows):
    for row_idx, row in enumerate(rows, start=2):
        for col_idx, header in enumerate(headers, start=1):
            cell = sheet.cell(row_idx, col_idx, row.get(header, ""))
            cell.font = SAMPLE_FONT
            cell.fill = SAMPLE_FILL


def write_sheet(wb, title, headers, required, sample_rows):
    sheet = wb.create_sheet(title)
    style_header(sheet, headers, required)
    write_rows(sheet, headers, sample_rows)
    return sheet


def write_ref(wb, title, headers, rows=None):
    sheet = wb.create_sheet(title)
    style_header(sheet, headers, set())
    if rows:
        for row_idx, row in enumerate(rows, start=2):
            for col_idx, value in enumerate(row, start=1):
                sheet.cell(row_idx, col_idx, value)
    return sheet


def main():
    wb = Workbook()
    instructions = wb.active
    instructions.title = "Instructions"
    lines = [
        "Asset Data Migration Template",
        "",
        "Send this file to the client. They fill the Assets sheet (and optional child sheets). We import with:",
        "  php spark assets:import path/to/filled.xlsx --requester <user_id>",
        "",
        "Sheets",
        "  Assets     — one row per asset. Required: asset_key, name, and cost_center_name or cost_center_id.",
        "  Reagents   — optional. Link with asset_key. Provide item_name, item_code, or item_id.",
        "  Lab_tests  — optional. Link with asset_key. lab_test_key is a workbook-only key for Machines.",
        "  Machines   — optional. Link with asset_key plus lab_test_key (preferred) or lab_test_name.",
        "  Ref_*      — lookup lists. Ref_branches is prefilled. Other catalogs stay header-only.",
        "",
        "Rules",
        "  1. Do not rename sheets or header cells. Add data from row 2 downward. Leave unused rows blank.",
        "  2. asset_key must be unique in this file. It is not stored; it only links child rows.",
        "  3. Rows whose asset_key starts with SAMPLE are examples and are skipped on import.",
        "  4. as_of must be YYYY-MM-DD (example: 2026-06-01).",
        "  5. name must be unique per branch among existing non-deleted assets. Duplicates are skipped, not updated.",
        "  6. Each asset is imported into that cost center clinic database only.",
        "  7. Prefer a name from the live catalog. IDs are optional if you already know them.",
        "  8. item_code is accepted as the catalog item id (numeric) or the exact item name. There is no separate item_code column in the database.",
        "  9. Unused Reagents / Lab_tests / Machines sheets can stay blank.",
        "",
        "Cost centers (also listed on Ref_branches)",
        "  1 Mobile, 2 Oakridge, 3 Mactan, 4 Consolacion, 7 Mantlewood, 5 Warehouse, 6 Pharmacy",
        "",
        "Yellow headers are required. Blue headers are optional.",
    ]
    for idx, line in enumerate(lines, start=1):
        cell = instructions.cell(idx, 1, line)
        if idx == 1:
            cell.font = TITLE_FONT
        cell.alignment = Alignment(wrap_text=False)
    instructions.column_dimensions["A"].width = 140
    instructions.row_dimensions[1].height = 24

    assets = write_sheet(
        wb,
        "Assets",
        ASSET_HEADERS,
        ASSET_REQUIRED,
        [
            {
                "asset_key": "SAMPLE-001",
                "name": "Sysmex HISCL 800",
                "cost_center_name": "Mobile",
                "cost_center_id": 1,
                "useful_life_years": 5,
                "end_user_location": "Laboratory",
                "cost": 10000,
                "as_of": "2026-06-01",
                "consumption_unit": "unit",
            },
            {
                "asset_key": "SAMPLE-002",
                "name": "Office Desktop PC",
                "cost_center_name": "Oakridge",
                "cost_center_id": 2,
                "useful_life_years": 3,
                "end_user_location": "Admin",
                "cost": 45000,
                "as_of": "2026-01-15",
            },
        ],
    )

    name_dv = DataValidation(
        type="list",
        formula1="Ref_branches!$B$2:$B$8",
        allow_blank=True,
        showDropDown=False,
        showErrorMessage=True,
    )
    name_dv.add("C2:C1000")
    assets.add_data_validation(name_dv)

    id_dv = DataValidation(
        type="list",
        formula1="Ref_branches!$A$2:$A$8",
        allow_blank=True,
        showDropDown=False,
        showErrorMessage=True,
    )
    id_dv.add("D2:D1000")
    assets.add_data_validation(id_dv)

    write_sheet(
        wb,
        "Reagents",
        REAGENT_HEADERS,
        {"asset_key"},
        [{"asset_key": "SAMPLE-001", "item_name": "Sample Reagent"}],
    )
    write_sheet(
        wb,
        "Lab_tests",
        LAB_TEST_HEADERS,
        {"asset_key", "lab_test_key"},
        [
            {
                "asset_key": "SAMPLE-001",
                "lab_test_key": "LT-001",
                "lab_test_name": "Complete Blood Count",
            }
        ],
    )
    write_sheet(
        wb,
        "Machines",
        MACHINE_HEADERS,
        {"asset_key"},
        [
            {
                "asset_key": "SAMPLE-001",
                "lab_test_key": "LT-001",
                "lab_test_name": "Complete Blood Count",
                "item_name": "Sample Analyzer",
                "qty": 1,
                "unit_name": "unit",
            }
        ],
    )

    write_ref(
        wb,
        "Ref_branches",
        ["id", "name", "db_group"],
        [
            [1, "Mobile", "mobile"],
            [2, "Oakridge", "oakridge"],
            [3, "Mactan", "mactan"],
            [4, "Consolacion", "consolacion"],
            [7, "Mantlewood", "mantlewood"],
            [5, "Warehouse", "warehouse"],
            [6, "Pharmacy", "pharmacy"],
        ],
    )
    write_ref(wb, "Ref_asset_groups", ["id", "name"])
    write_ref(wb, "Ref_suppliers", ["id", "name"])
    write_ref(wb, "Ref_items", ["id", "name", "item_code"])
    write_ref(wb, "Ref_lab_tests", ["id", "name"])
    write_ref(wb, "Ref_units", ["id", "unit", "item_id"])

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    wb.save(OUTPUT)
    print(f"Wrote {OUTPUT}")


if __name__ == "__main__":
    main()
