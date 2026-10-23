# Asset data migration

Send [`docs/templates/asset_migration_template.xlsx`](templates/asset_migration_template.xlsx) to the client. They fill the workbook; we import it internally. There is no public upload API.

Regenerate the blank template:

```bash
php scripts/generate_asset_template.php
```

If PhpSpreadsheet is not installed, the script falls back to `python3 scripts/generate_asset_template.py` (needs `openpyxl`).

## Client fill rules

Instructions are also on the **Instructions** sheet.

| Sheet | Purpose |
| --- | --- |
| **Assets** | One row per asset. Required: `asset_key`, `name`, and `cost_center_name` or `cost_center_id`. |
| **Reagents** | Optional. Link with `asset_key`. Provide `item_name`, `item_code`, or `item_id`. |
| **Lab_tests** | Optional. Link with `asset_key`. `lab_test_key` is workbook-only and links **Machines**. |
| **Machines** | Optional. Link with `asset_key` plus `lab_test_key` (preferred) or `lab_test_name`. |
| **Ref_*** | Lookups. **Ref_branches** is prefilled. Other catalogs stay header-only. |

Rules:

- Do not rename sheets or header cells. Data starts at row 2.
- `asset_key` is unique in the file and is not stored. It only links child rows.
- Rows whose `asset_key` starts with `SAMPLE` are examples and are skipped.
- `as_of` must be `YYYY-MM-DD`.
- `name` must be unique per branch among non-deleted assets. Existing names are skipped, not updated.
- Each row is written to that cost center clinic database only.
- Prefer catalog names. IDs are optional if already known.
- `item_code` is accepted as the catalog item id (numeric) or the exact item name.

Cost centers: `1` Mobile, `2` Oakridge, `3` Mactan, `4` Consolacion, `7` Mantlewood, `5` Warehouse, `6` Pharmacy.

## Import

```bash
php spark assets:import path/to/filled.xlsx --requester 72
php spark assets:import path/to/filled.xlsx --requester 72 --dry-run
php spark assets:import path/to/filled.xlsx --requester 72 --stop-on-error
```

- `--requester` is required. It is stored as `added_by`.
- `--dry-run` validates and resolves names only. No inserts.
- `--stop-on-error` stops after the first failed asset. Already created assets stay.

The command writes a CSV report next to the source file (`asset_import_report-YYYYMMDD-HHMMSS.csv`), or under `docs/templates/` if that directory is not writable. Columns: `sheet`, `row`, `asset_key`, `status`, `message`, `asset_id`.

Statuses: `created`, `would_create` (dry run), `skipped` (duplicate name on that branch), `failed`.
