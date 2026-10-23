# Billing runtime dependency repair

This release fixes a missing production `gl_journal_base_remark` helper and identified collections whose eligible allocations explicitly use master `branch_id=0`. Master allocations retain accounting ID 0; positive branches retain their IDs. Null branch metadata is excluded. Header WHT still uses deterministic cumulative allocation and both sides remain balanced.

The legacy master direct-payment entry point now dispatches to the current shared lifecycle and retains legacy-cutoff protection without requiring master `gl_branch_id` or `gl_settings`. APV accounting rules are unchanged.

`install.sql` carries six definitions plus prerequisite checks: journal base/collection header formatters, generic journal builders, collection posting, and master direct-payment dispatch. Narrow collection/AR installers also carry the base helper and check its `gl_remark_append` dependency. `build.py` synchronizes these files without executing SQL.

## Installation

Use the selected master only. Pause affected writes/jobs and drain requests. Back up exact affected definitions and source/accounting data privately. Confirm selected environment and prerequisite/schema compatibility before running `mysql --show-warnings -u root -p mytemr_master < install.sql` (or `mytemr_master_uat`). Do not use `--force`, execute full GL scripts, or repost historical payments as part of installation.

Verify installed definitions, all static GL dependencies and qualified cross-schema calls, read-only helper execution under application credentials, original-column row fingerprints, and unchanged unrelated definitions. `audit_dependencies.py` audits all sixteen application schemas, excludes SQL literals/comments and actual/temporary table references, and lists dynamic SQL separately for review. It does not execute posting routines or change data.

DDL is not transactional. On failure keep writes paused, restore exact prior affected definitions (drop the newly added helper if originally absent), verify recovery, then restore prior scheduler/write settings. Do not automatically retry partial installation or restore old business-table dumps over newer records.

## Validation

Use a networking-disabled local MariaDB fixture with `BILLING_FIXTURE_SOCKET`/`BILLING_FIXTURE_USER`. `tests/billing_runtime_repair_check.py` executes the incomplete baseline before applying the actual runtime installer; `tests/billing_ar_movement_check.py` covers billing creation, branch-zero/mixed/null allocations, WHT, lifecycle edits/deletion, repeated posting and migrated/manual protection. Existing deposit and shared-fund checks cover retained behavior. No test sends business writes to a live application database.

Future postings only: UAT 1445/1446 and older missing postings are intentionally not repaired.

## Deployed corrective release

Production and UAT were repaired and verified on October 22, 2026. See [DEPLOYMENT.md](DEPLOYMENT.md) for exact scope, regression and live read-only evidence, restored settings, and private backup locations.
