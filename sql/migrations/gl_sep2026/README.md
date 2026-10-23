# September 2026 — master import

Source: `/Users/myt_waren/Downloads/General Ledger_Sep2026.xlsx`.
All entries go to `mytemr_master`; there is no location column.

## Reconciliation

- 174 account headers mapped; no unmapped accounts or skipped transaction lines.
- 3,779 approved journal entries, IDs **13998–17776**.
- 17,212 journal entry items and 17,212 general ledger rows.
- Debit **267,751,863.1300** and credit **267,751,863.1300** in the workbook, JE headers, items, and GL.
- All transaction dates are September 1–30, 2026.
- 78 data CSVs: 8 JE chunks, 35 item chunks, and 35 GL chunks; maximum 500 records each.
- Every item and GL source references a generated JE. Item and GL IDs use database auto increment.
- JE/GL `is_migrated = 1`; master cost center and branch are NULL, represented by `\N` following existing imports.

**636 individual journal groups are unbalanced** under the existing source/reference grouping.
See `unbalanced_journals.csv` for journal ID, source type, date, debit, credit, and difference.
The complete pack balances. Original grouping is preserved; no balancing entries were added.
Account mappings, including account names, are in `account_map.csv`.

## SSH import

Existing migration flags and master tables must already be installed.
Immediately before import check the generated ID range is still unused:

```sql
SELECT MAX(id) AS last_id FROM journal_entry;
SELECT COUNT(*) AS conflicting_ids FROM journal_entry WHERE id BETWEEN 13998 AND 17776;
```

Expected last ID is 13997 and conflicting IDs must be zero. If new journals have consumed IDs,
regenerate using a fresh start rather than importing conflicting IDs.

```bash
cd /home/mytemr/web/accounting-back.myt-emr.com/public_html/sql/migrations/gl_sep2026/master
mysql --local-infile=1 -u root -p mytemr_master < import.sql
```

Import only once. Do not use `--force`. A failed import can leave partial rows;
inspect errors and database state before attempting recovery. This pack has not been deployed.

## Regenerate

From the repository root, with Python and openpyxl available:

```bash
python3 -B sql/migrations/gl_jan2026/generate_gl_jan2026.py \
  --xlsx '/Users/myt_waren/Downloads/General Ledger_Sep2026.xlsx' \
  --out sql/migrations/gl_sep2026 --label 'Sep 2026' \
  --je-id-start 13998 --added-on '2026-09-30 23:59:59' --master-only
```

The generator uses the established March mapping worksheet and current `account_type.sql`.
Reconciliation and the unbalanced-journal report must be refreshed after regeneration.
