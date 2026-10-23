# Shared fund workflows in master

This release supports active `branch.type='shared'` dynamically. Business headers and
APVs retain the selected actual cost center. Accounting uses GL `branch_id=0` and JE
`cost_center_id=0`. No branches or historical postings are seeded.

## Prerequisites and review

Use MariaDB 10.4 or newer (the isolated checks use MariaDB 10.4).

- Apply only to `mytemr_master` or `mytemr_master_uat`, never a clinic schema.
- Existing seven source header tables, master APV/payment tables, master account/user/
  department tables, and GL/JE source and migrated-flag migrations must be installed.
- `journal_entry_item.type` must support `Automated`. Master must have no JE/GL
  feedback posting hooks. Do not create `gl_settings`.
- Review `preflight.sql` results and `SHOW CREATE` for all affected hooks/routines.
  The installer replaces the known `trg_gl_*` posting hook names, preserves independent
  audit/balance hooks, and retains APV/payment posting hooks. Direct fund source/child
  hooks are disabled by the APV-only releases. Resolve any custom
  posting hooks with different names before installation to avoid duplicate paths.
- Review active unique account mappings `100-004` (cash/check) and `201-111` (payable).
- A source record's cost center cannot be changed through a shared-scoped API.
  Linked funds/APVs/children must belong to that same center.

## Installation order (separate deployment authorization required)

1. Pause writes to these fund and APV workflows. Preserve the previous maintenance
   setting. Back up source/APV/payment tables and GL/JE/items, plus **all routines and
   triggers** in the selected master schema, to a private directory outside the web
   root. Capture a preflight result before any DDL. Keep exports out of Git.
2. Run the schema-local installer from this directory, without `mysql --force`:

   ```bash
   cd /path/to/release/sql/shared_funds
   mysql -u root -p mytemr_master < preflight.sql
   mysql -u root -p mytemr_master < install.sql
   ```

   For UAT select `mytemr_master_uat` instead. The standalone batch asserts the target and
   checks feedback hooks first;
   schema DDL is additive. New nullable APV ownership does not reassign old records.
   Missing child/attachment/entry tables are created without copying clinic data.
3. `schema.sql` initializes cutoffs from **this selected master schema's** existing
   APV/direct-payment IDs using `INSERT IGNORE`; reinstalling never advances them.
   Legacy APVs without explicit entries retain their accounting until entries are
   supplied. Review/restore the cutoff with the rest of the backup on rollback.
4. Deploy the matching application files while writes remain paused. Confirm two
   shared centers and one clinic through create/GET/status/attachment/payment paths.
   Verify actual source ownership, GL/JE accounting center 0, and one active journal
   per source. Restore the previous maintenance setting after verification.

`install.sql` is a flattened batch so installation does not continue past a failed
prerequisite through nested SOURCE commands. `posting.sql` is identical in production and UAT. `build.py` embeds canonical `posting.sql` and synchronizes its block plus the
additive schema into both full master trigger scripts. Run `python3
sql/shared_funds/build.py` after editing the generator/schema. Scoped installation
leaves all billing/collection/deposit procedures and hooks unchanged; do not replace
complete master trigger scripts for a scoped deployment.

## Accounting behavior

- Cash advances, change funds, petty cash funds, liquidations, reimbursements and
  replenishments post through APVs only. Source/child changes and compatibility
  procedures neither create nor retire direct-source accounting. Existing history stays
  unchanged. See `../funds_apv_only/README.md`.
- Direct-payment accounting remains on explicit APVs; prepaid amortization now also
  scans shared master data once and posts its completed journals to master GL.
- Explicit APVs retain payable/balance validation, VAT/WHT entries supplied by the
  existing API, and legacy safeguards. Payment postings remain paired AP/bank entries.
- APV approval changes/cancellation and payment-header edits/deletion rebuild the
  relevant automated APV/payment source only. History is
  soft deleted. Imported (`is_migrated=1`) GL/JE and manual journals are retained.
- Master direct payments use the shared-master dispatcher. Existing nonshared master
  APV-payment posters, when installed, remain the guarded fallback for those
  payment details. No historical repost is performed.

## Rollback

Pause writes again. Do not run a blanket GL delete or historical rebuild. Back up any
new business records first. Run `rollback.sql` to remove the release-owned posting
hooks/routines, then restore the **exact prior** routines/triggers from the selected
schema's private backup before restoring application files or resuming writes.
`rollback.sql` alone is not a complete rollback. Keep additive columns/tables to avoid
losing new records; restore accounting data only from a verified, scoped backup if
post-install business activity requires it. Preserve independent audit and APV-balance
triggers. Verify billing/collection/deposit definitions still match their backups.

## Local validation

Use a disposable networking-disabled MariaDB instance. These scripts never use an
application database connection. The shared fixture is recreated for each run:

```bash
export BILLING_FIXTURE_SOCKET=/private/tmp/billing_deposit_fixture_20261021/mysql.sock
python3 tests/shared_funds_check.py
/opt/homebrew/bin/php tests/shared_funds_models_check.php
```

The PHP checks inject fixture-only named connections to test real controller routing
and merged master pagination. They also cover bound filters, IDs, links, attachments,
APV entries, ownership, dynamic branches and unchanged clinic model scoping. SQL checks
exercise actual MariaDB hooks, approvals/edits/cancellation, explicit APV validation,
payment lifecycle, repeat installation/posting and migrated/manual preservation.
