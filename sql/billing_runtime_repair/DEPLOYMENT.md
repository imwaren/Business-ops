# Corrective runtime deployment — October 22, 2026

Production master was repaired and verified before UAT master. The release changes future posting behavior only. Existing UAT collections 1445/1446 and older missing postings were not rebuilt.

## Confirmed defects and corrections

- Production journal builders referenced absent `gl_journal_base_remark`. The canonical helper is now installed and included with narrow collection/AR installers.
- Identified collections excluded positive allocations on explicit master branch 0. Totals and branch distribution now include nonnegative, nonnull branch IDs. Master postings retain ID 0; null metadata remains excluded.
- Production retained a legacy direct-payment procedure that required absent master `gl_branch_id`. Both environments now use the canonical shared-master dispatcher with legacy-cutoff protection and no master `gl_settings` dependency.

The six-definition runtime release verified journal formatters/builders alongside the posters. Only three definitions required replacement/addition in production (`gl_journal_base_remark`, `gl_post_billing_payment`, `gl_post_direct_payment`), and two in UAT (the latter two). No hooks or schema were replaced by this corrective rollout.

## Evidence

- Original-column SHA-256 fingerprints matched for all 49 protected tables in each master. Existing source/accounting rows and APV cutoffs remained unchanged.
- Unrelated routine/trigger definitions and captured security metadata were protected across all sixteen schemas during each installation.
- The corrected audit scanned 2,632 routine/trigger objects across sixteen databases, verified ten qualified cross-schema calls, and found **zero unresolved required GL dependencies**. Four optional APV fallback calls are guarded by explicit routine-existence checks; unrelated dynamic SOA helpers are classified separately. Table identifiers are not treated as function calls.
- Five read-only billing/memo/collection/context helper checks passed under each deployed application's own credentials.
- Local actual-trigger checks passed: 334 billing/collection, 35 deposit, 243 shared-fund PHP, 13 incomplete-baseline runtime, and eight dependency-auditor classification checks. The existing shared SQL fixture also passed its 60 assertions.
- No unexpected installation warnings. The expected missing-function note during the production helper's `DROP IF EXISTS` was captured privately.
- Both environments restored prior settings: `read_only=0`, event scheduler `OFF`, cron `active`.
- No live business/posting tests, application deployment, data cleanup, historical repost, or synthetic live transactions were executed.

The October 21 deployment report has been annotated: selected-definition matching and unchanged data did not prove dependency closure or branch-zero posting behavior. This release adds those checks and incomplete-baseline regressions.

## Private recovery files on VPS6

- Production: `/root/billing_runtime_20261022_release/prod`
- UAT: `/root/billing_runtime_20261022_release/uat`

Each contains compressed original definition/data backups, exact affected-definition snapshots, before/after fingerprints and dependency audits, installation warnings, backup checksums, verification results, and maintenance restoration evidence. The release root contains the scoped manifest, runner, SQL package checksums, and `completion.json`. Backups remain private; no records or encryption helpers are published here.

Manifest SHA-256: `3c9eb58584f4f4fa50ada8448139ed7c0903ca8dd4017b027b2fd7d005afce39`

Package checksum-list SHA-256: `4fb343661dcd129243629ab06b673b27c0adcd363aa10df6e3acb57d6848afaf`

Restore only exact affected definitions under a write pause if recovery is needed, then verify before resuming. Never overwrite newer business records with old table dumps or automatically rerun a partial deployment.
