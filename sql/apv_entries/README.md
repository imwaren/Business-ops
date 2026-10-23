# APV entry accounting — forward-only deployment

## Release result

Deployed to UAT and production on 2026-10-08, UAT first. All five clinic schemas per environment have the additive schema, immutable cutover registry, and scoped posting definitions. Nine PHP files per environment passed remote syntax and SHA-256 checks.

**No historical backfill, GL/JE rebuild, successor APV creation, or legacy-table deletion was executed.** Historical GL/JE, APV headers, direct-payment headers, and both existing entry tables had identical before/after fingerprints during each rollout. Original dates remain unchanged. Master triggers and unrelated schedules were not changed.

Recovery files are on **both VPS5 and VPS6** under `/root/apv_forward_20261008_2155`, with separate `uat` and `prod` directories. Local verification evidence is in `deployment_20261008/`.

## Compatibility rules

- `apv_accounting_cutover` captures the highest existing APV/direct-payment IDs once per branch. `INSERT IGNORE` preserves those cutoffs on redeployment.
- A pre-cutover APV with no child-entry history is legacy. Existing approved/open/closed APVs remain payable; payment-driven status updates preserve their existing GL/JE.
- Legacy first approval and edits to amount, withholding, source, or posting date require explicit balanced entries. Non-financial metadata edits preserve accounting. Cancellation/deletion soft-retires the relevant postings.
- New APVs require active accounts, four-decimal non-negative amounts, one positive side per line, balanced totals, and net credit to `201-111` equal to the header amount.
- Explicit entry adoption atomically replaces the active child set and superseded APV GL/JE, retaining soft-deleted history. Any child-entry history permanently ends legacy classification.
- New direct payments do not post ordinary GL/JE. Legacy approved direct-payment updates preserve their existing postings. An approved entry-based APV linked to that payment retires the superseded ordinary direct-payment posting.
- Prepaid entry-based APVs require separate expense/prepaid allocations totaling the direct-payment amount. Monthly allocation splitting preserves four decimals, historical journals, and period deduplication.
- GET returns existing entry arrays without synthesizing historical children. Legacy source reporting reads active historical GL; linked journal responses preserve legacy direct-payment movements.
- `direct_payment_entry` remains intact in every schema for recovery. Its old table/model are no longer used by the active entry accounting flow; the deployed old model file was retained as recovery material.

## Deployment and verification

The rollout backed up affected tables, exact routine/trigger definitions, API files, and routing. It temporarily returned HTTP 503 only for APV/direct-payment routes and the prepaid-amortization route. Original routing was restored after validation; no cron definitions were edited.

Only `schema.sql`, `cutover.sql`, and environment-specific `posting_*.sql` were applied. The scoped installer updates APV/direct-payment posting, adds child hooks and the legacy update guard, and removes old direct-payment child hooks. Receipt-note, APV-payment, master, and unrelated posting definitions are unchanged.

Verification completed:

- **101 isolated checks passed**, including rollback, legacy payments, first-approval guards, adoption, cancellation, concurrent amortization workers, and registry repeatability.
- Nine deployed PHP files per environment match local SHA-256 hashes and pass PHP syntax validation.
- All ten clinic schemas have matching scoped definitions and retain the legacy table.
- Before/after fingerprints match for all seven backed accounting tables in each schema.
- Read-only deployed model checks passed across all five connections in each environment: APVs, entries, journals, direct payments, amortization selection, and GL source reporting.
- HTTP GET checks reached both APIs and returned 401 without valid credentials. Authenticated end-to-end verification was therefore unavailable.

## Deployment package and recovery

- `scripts/apv_forward_db.py`: scoped backup, baseline, additive installation, definition/fingerprint verification, and restoration of original definitions.
- `scripts/apv_forward_app.py`: file backups, targeted route hold, atomic file replacement, syntax/checksum checks, and routing/file restoration.
- `local_checksums.json`: deployed PHP release hashes.
- `docs/postman/apv_entry_accounting.postman_collection.json`: importable placeholder-based examples. Do not use write examples as live deployment tests.

If recovery is needed, hold affected routes, restore the environment's original PHP files and scoped SQL definitions from its server backups, verify checksums and accounting fingerprints, then restore routing. Additive schema may remain. No table-data restore is required merely to undo this release because rollout did not mutate historical accounting.

## Deferred historical migration

The 18 historical exceptions remain documented in `audit/exceptions.tsv`; they are intentionally not the gate for this forward-only release. Historical account selection, successor vouchers, and legacy-table removal require a separate resolved migration.

`migrate_*.sql`, `rebuild.sql`, and `drop_legacy.sql` are retained for that future task and **were not used in this rollout**. Their historical mapping/backup gates must not be bypassed.
