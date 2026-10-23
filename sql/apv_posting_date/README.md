# APV recognition date

Every APV type, including refund, recognizes GL and JE on `COALESCE(DATE(approved_on), DATE(added_on))`. The due date is not an accounting recognition date. APV payment details retain their independent payment dates. No API payload, schema, ownership, amount, account, remark, approval rule, legacy cutoff, or migrated/manual protection changes.

## Scoped deployment

`build.py` extracts only `gl_post_apv` into four installers: production/UAT, master/branch. Branch installers allow the five clinic databases plus warehouse and pharmacy, and reference the matching environment's master accounts. Install only the selected matching file; do not replace complete GL scripts.

Verify live prerequisites and compare old/new bodies: the date expression must be the only semantic difference. Stage/checksum privately, pause affected writes/jobs and drain requests, and back up exact prior definitions plus APV/source/accounting data. Run the selected installer using the SSH SQL client with `--show-warnings`, without `--force`. Verify installed definitions, dependency closure, unchanged historical fingerprints, and unrelated hooks, then restore prior settings. Production must pass before UAT.

On failure retain the pause while restoring the exact previous APV routine; verify recovery before resuming. Do not automatically retry a partial installation or load older business-table dumps over newer rows.

## Local verification

Use only a disposable local MariaDB Unix socket. Run `tests/apv_accounting_check.php` then `tests/apv_posting_date_check.py` for real GL/JE synchronization, clinic/warehouse/pharmacy identities, refund and other APVs, approval precedence, creation fallback, repeated posting, balanced items, encrypted patient context, and unchanged payment dates. `tests/shared_funds_check.py` covers every shared APV type, clinic-owned master refunds, both scoped master installers and legacy/manual protections.

Existing postings are not repaired or reposted. Only subsequent normal posting/rebuilding uses this date rule. Remote execution is a separately authorized deployment action; no installer calls a posting or backfill procedure.

## Deployed release

Production and UAT deployment completed on October 22, 2026. See [DEPLOYMENT.md](DEPLOYMENT.md) for verification, exact boundaries, checksums and separate private backup locations.
