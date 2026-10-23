# Fund accounting through APVs only

Change funds, petty cash funds (`petty_cash`), liquidations, reimbursements and replenishments no longer create or retire direct-source GL/JE/items. Parent/child accounting hooks are removed; their posting procedures remain documented no-ops. Replenishment no longer dispatches petty-cash posting, and clinic backfills no longer select or dispatch these sources. Existing independent audit, balance and workflow hooks remain in place.

Accounting continues through the existing APV approval/payment lifecycle with explicit-entry validation, existing accounts/amounts/attribution, approval-first APV dates and separate payment dates. Source workflows, pickers, API payloads and schemas are unchanged. Existing direct-source history is deliberately retained; no cleanup/repost is performed.

## Release files and installation

`build.py` generates `install_branch.sql` and `install_master.sql` from canonical sources. Each drops only known direct accounting hook names and installs the five compatibility procedures; the branch package also updates backfill dispatch/selection. Use only supported existing paths; do not introduce absent backfill routines into warehouse/pharmacy. The older `cash_advance_apv_only` builder now reads the same no-op liquidation/backfill definitions and cannot restore disabled liquidation accounting.

Before installation inspect the selected environment, exact routine/trigger bodies and required dependencies. Stop on unknown/mixed accounting and business hooks. Stage/checksum privately, pause affected writes/jobs and drain requests, then back up exact affected definitions and relevant source/accounting data. Apply the matching scoped SQL with warnings capture, without `--force`. Do not replace complete GL scripts.

Verify compatibility no-ops, absent direct parent/child accounting hooks, inactive direct-source backfill paths, unchanged APV/payment routines, historical row fingerprints and unrelated definitions. Restore prior settings after success. Production must pass before UAT. On failure restore exact prior definitions and verify recovery before resuming; do not automatically retry or overwrite newer business rows with old dumps.

## Local validation

Only use a disposable local MariaDB socket. `tests/funds_apv_only_check.py` covers source/child edits, approval, cancellation, historical automated/migrated/manual rows, compatibility calls, old installers, backfill queues, all five APV types and their payments. `tests/apv_accounting_check.php`, followed by `tests/apv_posting_date_check.py`, exercises clinic/warehouse/pharmacy identities with approval-date precedence, balanced GL/JE/items, repeated posting and payment-date preservation. Shared-fund model/controller regressions retain source selection and ownership checks.

No application deployment, schema change, historical cleanup, posting/repost call or synthetic live transaction is included in this release.

## Deployment record

See [DEPLOYMENT.md](DEPLOYMENT.md) for the October 22 production/UAT verification and private backup locations.
