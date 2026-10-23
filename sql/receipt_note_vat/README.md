# Receipt-note parent references and Input VAT

Future `receipt_note_item` postings include `receipt_note #<parent ID>` in GL,
journal headers and journal items, retaining item context and grouping. Receipt VAT
uses `gl_account_id('106-000')` exclusively; an item's VAT account no longer overrides
INPUT VAT. Account 169 in the reported database maps to that code; IDs elsewhere may
differ. VAT calculations, amounts, inventory/payable mappings, dates and branch
attribution are unchanged. No historical repair or repost is included.

## Local sources

Edit clinic canonical SQL and `fragments/general_ledger_remarks_diagnostic.sql`, then
synchronize both clinic variants. Shared header context is canonical in
`shared_funds/posting.sql`; procurement is generated from clinic posting. Run:

```bash
python3 sql/shared_funds/build.py
python3 sql/shared_procurement/build.py
python3 sql/refund_apv_posting/build.py
python3 sql/receipt_note_vat/build.py
```

## Scoped installation (separate deployment authorization required)

1. Select only the already-installed clinic-style or shared-master accounting path.
   Verify database identity, account 106-000, exact live definitions, definer/security
   metadata, helper dependencies, and independent hooks. Resolve customizations before
   replacing definitions. The installer rejects absent paths or mapping/dependency gaps.
2. Pause receipt/procurement writes and related jobs; retain their prior settings and
   drain active requests. Back up exact affected routine definitions and receipt/item,
   GL, JE and journal-item data privately outside the web root. Record fingerprints,
   unrelated definitions and checksums. Preserve the installed definer/sql_mode when
   preparing the release; default CREATE uses the installation session's identity.
   Also preserve each routine's `character_set_client`, `collation_connection`, and
   `Database Collation`. A routine may predate a change to its database's default.
   Recreating it with exact metadata can require an explicitly approved temporary
   database-default collation change while writes are paused, immediately restoring
   the current default afterward. This does not alter existing tables or rows.
3. Stage/checksum the matching scoped SQL. Clinic-style production uses
   `install_prod.sql`, shared master uses `install_prod_master.sql`; UAT uses the
   corresponding `install_uat.sql` / `install_uat_master.sql`. These contain only the
   changed remark/context helpers and receipt posting procedure; they do not replace hooks.
   Apply without `--force`, capturing warnings/errors, for example:

   ```bash
   mysql --show-warnings -u root -p mytemr_diagnostic < install_prod.sql
   ```

4. Verify installed definitions, dependency closure and the INPUT VAT mapping. Verify
   original source/accounting fingerprints and unrelated hooks/routines are unchanged.
   Restore prior write/job settings. Do not run synthetic transactions or repost old
   receipts on a live database. Existing entries change only on a later normal rebuild.
5. On any failure, stop without retrying. Keep writes paused, restore exact prior
   affected definitions from the selected database's backup, verify recovery and
   fingerprints, then restore prior settings. DDL is not transactionally rolled back;
   never restore business data blindly or run a blanket trigger-script replacement.

## Validation

`tests/receipt_note_vat_check.py` uses disposable local MariaDB schemas for clinic and
shared-master production/UAT variants, actual posting/JE hooks, VAT inclusion/exclusion,
zero VAT, multiple items and repeated posting. No remote database is used.

## Deployment record

Production and UAT deployment evidence and backup locations: [DEPLOYMENT.md](DEPLOYMENT.md).

## Receipt quantity correction

The current sources also correct receipt line extension: when `amount` is present,
posting uses `amount * COALESCE(qty, 0)`. Stored `vat` is already line-level and is
not multiplied again. When `amount` is null, `total` remains an already extended
legacy fallback. Zero/missing quantities with a unit amount do not post a line.
Existing VAT rules, account selection, receipt dates and source grouping remain.

Receipt 30/item 24 example: unit amount 250 × quantity 10 = gross 2500; stored VAT
267.86 yields net 2232.14, with INPUT VAT 267.86 and AP Transitory credit 2500.
The inventory-account mapping is not changed by this correction.

The quantity correction was deployed to **UAT only on 2026-10-23**; production is
awaiting user confirmation. See [UAT quantity deployment evidence](DEPLOYMENT_QUANTITY_UAT.md).
The earlier deployment record describes the VAT/reference release.
For a separately authorized installation, follow the scoped installation steps above,
using the newly generated matching environment/path installer. Preflight now also
requires `receipt_note_item.qty`. Back up exact prior routine definitions and metadata,
then replace only the scoped receipt definitions. Verify future behavior without
reposting historical receipts. Restore the exact prior definitions/creation metadata
on failure, retaining the prior database defaults and write/job settings. No source
rows, schema, inventory movements or historical postings should be changed.
