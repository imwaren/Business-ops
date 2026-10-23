# Deployment — October 22, 2026

## Result

Production completed and passed verification before UAT started. Both environments passed. Deployment used VPS6 through SSH port 1969, with application read-only access checks through VPS5. No application files or schemas were deployed, no historical entries were changed, and no live posting/reposting or synthetic transactions were executed.

The five fund posting procedures are compatibility no-ops. Direct parent/child accounting hooks are absent. Clinic backfill selection/dispatch excludes these sources. APV recognition/payment routines, independent business hooks and existing accounting history remain unchanged.

## Production

Updated eight databases: `mytemr_master`, `mytemr_diagnostic_mobile`, `mytemr_diagnostic`, `mytemr_diagnostic_mactan`, `mytemr_diagnostic_conso`, `mytemr_diagnostic_mantlewood`, `mytemr_oakridge_warehouse`, `mytemr_oakridge_pharmacy`.

Removed 89 installed direct accounting hooks. Updated five compatibility procedures per database and the two existing backfill procedures in each clinic. Warehouse/pharmacy had no installed backfill procedures; those absent paths were not added.

Private backup/evidence directory on VPS6: `/root/funds_apv_only_20261022_release/prod`. Contains 16 compressed SQL definition/data archives, `backup_checksums.json`, before/after fingerprints, inspected definitions, dependency reports, installation logs, `verified.json` and `maintenance_restored.json`.

## UAT

Updated the same eight database names with `_uat` suffix, after production verification and application access checks passed. Removed 89 installed direct accounting hooks. Applied the same compatible procedure changes; absent warehouse/pharmacy backfill paths were not added.

Private backup/evidence directory on VPS6: `/root/funds_apv_only_20261022_release/uat`. Contains the same evidence categories and 16 compressed SQL archives, independently checksummed.

## Verification evidence

For each environment:

- All 153 selected source/accounting tables retained identical before/after fingerprints.
- Unrelated routine/trigger definitions remained unchanged, including APV/payment, billing, collection, deposit and independent audit/workflow hooks.
- Required dependency audit across all 16 databases reported zero missing references and zero unresolved dynamic calls requiring review; guarded optional and unrelated dynamic paths were classified separately.
- Installed no-op procedures and absent source/child accounting hooks were verified without invoking live posting procedures.
- Eight application-credential read-only checks passed, confirming schema identity and source/procedure access.
- Installation logs contained no errors or warnings; expected missing-hook notes were permitted.
- Prior settings were restored: `read_only=0`, event scheduler `OFF`, cron `active`.

Release staging: `/root/funds_apv_only_20261022_release`, outside the web root. `completion.json` consolidates results. Release manifest SHA256: `b599dba3868c75689248af3363f6faa8bfb5ce157cba76781efb9cacc31942dc`. Package checksum-list SHA256: `3eec95c7c6897dbd7f4ac730388298fe4fb453bb5d3b48e81b94668f7b08233a`.

## Local validation

Real isolated MariaDB checks passed before deployment:

- Fund APV-only lifecycle/history/posting: 64 checks, plus 88 shared fixture assertions.
- APV accounting: 118 checks.
- Approval-first APV dates across refund/fund types and physical identities: 392 checks.
- Shared-fund model/controller regressions: 243 checks.
- Cash-advance compatibility installer regressions passed.
- Generator repeatability and whitespace checks passed.

These local checks exercise actual trigger execution, balanced APV GL/JE/items, separate payment dates, source/child changes, legacy compatibility/backfill calls, repeat installation/posting, and automated/migrated/manual history preservation. Live verification was read-only; no live business workflow was synthesized.

## Recovery

No recovery was required. For any later rollback, pause affected writes/jobs and restore exact affected definitions from that environment's private backup after reviewing subsequent activity. Do not run historical cleanup or blindly restore old business data. Keep prior settings recorded in `maintenance.json` and restore them only after recovery verification.
