# Pending accounting SQL deployment — October 21, 2026

> **Verification correction (October 22):** The original rollout compared selected definitions and preserved data, but did not validate the full routine/trigger dependency graph or master branch-zero collection behavior. Production was left without `gl_journal_base_remark`, and its legacy direct-payment routine still referenced missing `gl_branch_id`. The collection eligibility filter excluded explicit master allocations. The results below describe the original checks, not complete functional acceptance. See `billing_runtime_repair/README.md` and its deployment evidence for the corrective release.

Deployed via VPS6 SSH port 1969, **production first, then UAT**. Production verification and maintenance restoration completed before UAT began. No application code, financial repost, historical cleanup, or synthetic business transaction was deployed.

## Installed scope

- Collection journal-header remarks and generic rebuild formatting.
- Refund APV creation-date posting and master-patient context, including linked payment remarks.
- Shared-fund dependency corrections and shared procurement schema/posting dependencies.
- Cash advances post through APVs only; compatibility routines are no-ops and direct hooks/indirect liquidation/backfill dispatch are disabled.
- Eight reviewed master procurement hooks calling absent legacy routines were removed per environment and replaced with the shared posting path. Exact original definitions were backed up.

Both environments include master, the five diagnostic databases, warehouse, and pharmacy. Warehouse/pharmacy received only applicable installed paths; absent clinic backfill routines were not introduced. Complete GL scripts were not applied.

## Verification

- Production: eight databases; original-column SHA-256 fingerprints matched across 347 existing tables.
- UAT: eight databases; original-column SHA-256 fingerprints matched across 347 existing tables.
- Installed scoped definition bodies match the release manifest. Unrelated routine/trigger bodies and their captured security metadata are unchanged.
- Existing APV cutoffs and business/accounting records are unchanged. Additive procurement metadata, registries, and indexes were installed without backfill.
- Deployed application credentials passed nonexecuting cross-schema inventory permission checks for all seven physical destinations.
- Read-only missing-record collection/refund remark helper checks passed. No live write/posting tests were performed.
- Prior settings restored after each environment: `read_only=0`, event scheduler `OFF`, cron service `active`.

## Private backup and recovery evidence on VPS6

- Production: `/root/accounting_pending_20261021_release/prod`
- UAT: `/root/accounting_pending_20261021_release/uat`

Each environment contains 16 compressed backup files (schema/routine/trigger definitions and selected source/accounting/inventory data for eight databases), exact target definition snapshots, before/after fingerprints, verification results, maintenance restoration evidence, and `backup_checksums.json`. The release root contains the scoped deployment runner, release manifest/checksums, and `completion.json`. These files are private and contain no public download links.

Release manifest SHA-256:
`5c91f95a382146daf30479d5b64fc87e2789a783c2d77ba0f58a4cab150bda87`

Package checksum-list SHA-256:
`48c9646b39b797857f8a18ae972a8d7e6d6a42c9d3320bb15e967fe87c072b3c`

For recovery, pause affected writes and jobs first, restore only the required exact prior definitions from private backups, and verify before resuming. Retain additive schema unless separately reviewed. Do not load old business-table backups over newer records or automatically rerun an installation.
