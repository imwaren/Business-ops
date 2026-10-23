# Receipt quantity correction — UAT deployment, 2026-10-23

## Result

Completed over SSH to VPS6, port 1969. Updated one receipt posting procedure in each
of the eight UAT databases below; no targets were skipped. Master received
`gl_shared_post_receipt_note`; physical databases received `gl_post_receipt_note`.
Only these procedures changed; unchanged helpers and all triggers were preserved.

- `mytemr_master_uat`
- `mytemr_diagnostic_mobile_uat`
- `mytemr_diagnostic_uat`
- `mytemr_diagnostic_mactan_uat`
- `mytemr_diagnostic_conso_uat`
- `mytemr_diagnostic_mantlewood_uat`
- `mytemr_oakridge_warehouse_uat`
- `mytemr_oakridge_pharmacy_uat`

Production was not installed or paused. All production routine/trigger definitions
matched the preflight snapshot after deployment; global DB write/event settings and
production web/job configuration were not changed.

## Verification

- Definitions match the checksummed quantity release: unit `amount × qty`, stored
  line VAT unchanged, `total` fallback already extended, missing quantity treated as zero.
- INPUT VAT still resolves through code `106-000`; receipt parent references retained.
- Zero missing routine dependencies or unresolved dynamic calls in the 16-schema audit.
- Before/after fingerprints match across 89 UAT source, inventory and accounting tables.
- Original signatures, definers, SQL modes, security, client character sets,
  connection collations and creation database collations preserved.
- Installation log has zero warning/error lines.
- Eight read-only application connections passed VAT/helper calculation checks:
  net 2232.1400, VAT 267.8600, gross 2500.0000.
- UAT receipt 30/item 24 source was read without modification: amount 250, qty 10,
  VAT 267.86, total 2232.14; calculated extension 2500.
- No posting procedures, synthetic transactions or historical reposts were executed.
  Existing receipt 30 GL/JE rows therefore retain their historical values.

## Maintenance and recovery

UAT accounting HTTP traffic temporarily returned 503. Only UAT accounting cron entries
were paused. Fifteen UAT-only writer account/host identities were locked, active
requests/transactions drained, and idle connections closed before installation.
Original account lock states, web configuration and exact cron contents were restored
and verified. Production accounts/vhosts/jobs were left unchanged. Global read_only
remained 0, event scheduler remained OFF, and cron services remained active.

As previously authorized, warehouse and pharmacy UAT procedure creation required two
brief database-default collation changes to preserve original routine metadata.
Each default was restored immediately, and final defaults match preflight. Existing
tables and their collations were unchanged. No installation failure or rollback occurred.
Apache configuration checks passed; its existing ServerName advisory was informational.

Private VPS6 backup/evidence directory:
`/root/receipt_qty_uat_20261023/uat`

Contains 16 compressed SQL archives (data and definitions for each target), exact
pre-install definitions/metadata, before/after fingerprints, dependency audits,
`verified.json`, application probe results, warning logs, default-restoration records,
account-restoration evidence and `backup_checksums.json`.
Release checksums and consolidated evidence are under `/root/receipt_qty_uat_20261023/`.
Private VPS5 web/job recovery records are under `/root/receipt_qty_uat_20261023/`.

To recover this release, pause UAT only and restore the exact prior procedure bodies
and recorded creation metadata from these backups. Verify all protected definitions,
data fingerprints and defaults before restoring the recorded account/web/job states.
Do not restore business data blindly or install a complete trigger script.

## Release SHA-256

- `manifest.json`: `63bbb91e86f6a049d29a44ab830457fc5822a4f9bbd7fa8a89e2d1e7646da730`
- `install_uat.sql`: `aba3716e95cf793dc4959df0192e8540e8e713b07d9a43a7b3fb551f187f2518`
- `install_uat_master.sql`: `e2db11d59f309853b428520f74e612563fd59d133dd4eef9061e93f728341f82`

The staged installers supplied canonical procedure bodies and prerequisites; the
metadata-preserving runner installed only the changed procedures. Unchanged context
helpers in those installers were not replaced.

## Limits

Future UAT postings only. No application deployment, source-data changes, inventory
movements, or historical correction. Production deployment requires the user's later
confirmation. Local execution evidence: 116 receipt checks and 91 procurement checks
passed before this deployment; live acceptance remained read-only.
