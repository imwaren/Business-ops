# Refund APV posting date and master-patient remarks

Refund-specific remark changes remain scoped to refunds. The superseding APV posting-date policy now uses `COALESCE(DATE(approved_on), DATE(added_on))` for every APV type; `due_date` is never used for recognition. See `sql/apv_posting_date/`. Refund APV and linked payment remarks resolve the patient from the environment's master `refund.patient_id`, while transaction numbers and item context remain clinic-local. Payment accounting dates are unchanged. Missing/deleted master patients omit `Patient:` without using a clinic patient with the same ID.

## Files and prerequisites

- `install_prod.sql` / `install_uat.sql`: clinic-only replacement of `gl_post_apv` and `gl_remark_context`.
- `install_prod_master.sql` / `install_uat_master.sql`: replacement of the existing master APV poster and master refund-context helper, retaining the current master routing. Use only where those existing master definitions match this release's baseline; stop and reconcile differences rather than introducing another posting path.
- `build.py` regenerates these scoped files from the complete SQL sources. It executes no database SQL.

Requires the existing APV explicit-entry schema/cutover, GL/JE source helpers/hooks, and installed remark helpers including the correctly rendered `gl_patient_display_name` encryption configuration. Clinic scripts refer to `mytemr_master` or `mytemr_master_uat` respectively. These scoped files contain no encryption key or placeholder and do not replace the decryption helper.

## Installation order (separate deployment authorization required)

1. Confirm the environment, selected schema, current affected routine definitions, APV dependencies, and cross-schema read access to master `refund` and `patient`. Verify `refund.patient_id` is the master patient ID. Back up exact affected routine definitions and `general_ledger`, `journal_entry`, and `journal_entry_item` to a private directory outside the web root.
2. Pause APV, APV-entry, and APV-payment writes during routine replacement; record prior maintenance settings and drain active requests.
3. Apply the matching clinic installer separately to each intended clinic database using the SSH MySQL client without `--force`. Example (production Oakridge only):

   ```bash
   mysql -u root -p mytemr_diagnostic < install_prod.sql
   ```

   UAT uses the corresponding `*_uat` clinic database and `install_uat.sql`. Do not apply a clinic installer to master.
4. If updating an already-installed matching master APV path, apply the corresponding `install_*_master.sql` to that environment's master database. Do not install complete trigger scripts as part of this scoped deployment.
5. Compare installed definitions and confirm historical accounting rows are unchanged; restore prior write settings. Stop on the first error and retain the write pause until recovery is verified.

No installer calls a posting/rebuild procedure, modifies source amounts, changes schemas, relocates APVs, or repairs historical entries. Existing entries receive the new date/name only through a separately authorized normal rebuild or repair.

## Recovery

While writes remain paused, restore the exact two prior routine definitions for the affected schema from the backup. DDL is not transactional; do not automatically retry a partial installation. Verify restoration before resuming writes. No business rows should require restoration because installation executes definitions only.

## Local verification

`tests/apv_accounting_check.php` covers actual GL/JE trigger execution, approval-first posting with creation fallback, master/clinic patient ID collisions, encrypted Unicode names, missing/deleted patients, branch mismatch, item/transaction context, linked payment posting dates, repeated posting, and existing APV accounting/legacy/concurrency regressions. It accepts `BILLING_FIXTURE_SOCKET` and `BILLING_FIXTURE_USER` for an isolated local MariaDB fixture. Never point it at an application database.
