# APV date policy deployment — October 22, 2026

Deployed via VPS6 SSH port 1969, production first and UAT only after production verification and application access checks passed.

## Result

- Eight production and eight UAT databases updated: master, five clinics, warehouse, and pharmacy.
- Only `gl_post_apv` was replaced in each database. Preflight proved the date expression was the only semantic difference from each installed baseline.
- Every APV type, including refunds, recognizes GL/JE on `COALESCE(DATE(approved_on), DATE(added_on))`. No `due_date` reference remains in the installed recognition routines.
- APV-payment routines, amounts, accounts, remarks, branch attribution, approval rules and legacy/manual/migrated protections remain unchanged.
- Historical SHA-256 fingerprints matched for all 89 protected source/accounting tables per environment. Existing APV recognition dates were not repaired or reposted.
- Unrelated definitions were protected across all sixteen schemas. Installed routine security/creation metadata matched the captured originals.
- Dependency audit: sixteen schemas, no unresolved required GL dependencies. No unexpected installation warnings.
- Read-only application-credential checks passed for all eight databases in each environment. No live posting or synthetic business transactions were executed.
- Prior settings restored after each environment: `read_only=0`, event scheduler `OFF`, cron `active`.

## Local validation

- 118 isolated APV accounting checks, including refund approval-date precedence, creation fallback, encrypted patient context, payment-date preservation and legacy/concurrency regressions.
- 112 physical-branch date checks against production/UAT scoped installers, covering all five clinic identities, warehouse and pharmacy, repeated posting and matching balanced JE items.
- 88 shared-master SQL assertions, covering every APV type, clinic-owned master refunds, date consistency and definition-only scoped installers.
- 243 existing shared-fund PHP/controller checks.
- PHP syntax, repeatable SQL generation and whitespace checks passed.

## Private backup/evidence directories on VPS6

- Production: `/root/apv_date_20261022_release/prod`
- UAT: `/root/apv_date_20261022_release/uat`

Each contains sixteen compressed schema/definition and source/accounting backups, exact target snapshots, before/after fingerprints and dependency audits, warning logs, verification results, restoration evidence and backup checksums. The private release root contains the scoped manifest, installers, deployment runner, package checksums and `completion.json`. No backup data or encryption helpers are committed here.

Manifest SHA-256: `3e8c66d3d936d8c423ac6e4bf7321af7797b4046b6433d1c8a6831be0bebe0da`

Package checksum-list SHA-256: `00f5b143dcdf5ff38af56710eba393ee071a57153735d85021a320b7871e3f67`

For recovery, pause affected writes/jobs, restore only exact original APV routine definitions from the selected environment's private snapshots, verify, then restore settings. Do not load historical table dumps over newer business rows or automatically retry a partial installation.
