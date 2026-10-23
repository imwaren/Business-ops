# Weighted-cost accounting — production deployment, October 15, 2026

## Result and coverage

Deployment completed and passed verification on VPS6 for all seven production schemas:

- Mobile (`1`): `mytemr_diagnostic_mobile`
- Oakridge (`2`): `mytemr_diagnostic`
- Mactan (`3`): `mytemr_diagnostic_mactan`
- Consolacion (`4`): `mytemr_diagnostic_conso`
- Mantlewood (`7`): `mytemr_diagnostic_mantlewood`
- Warehouse (`5`): `mytemr_oakridge_warehouse`
- Pharmacy (`6`): `mytemr_oakridge_pharmacy`

The five clinics received only the revised `gl_post_item_history()` procedure. Warehouse and Pharmacy each received the 108-object diagnostic accounting package: 34 functions, 28 procedures, and 46 triggers. Their reviewed legacy direct-payment procedures were backed up and replaced with the current APV-compatible behavior. Both census procedures in each database and existing inventory/audit triggers were preserved.

Inventory movements now use `ABS(item_history.quantity) * IFNULL(master.item.weighted_cost, 0)`, with no fallback to purchase cost. Accounts, dates, eligibility, quantity handling, remarks, and journal grouping remain consistent with the verified diagnostic source.

Master and UAT accounting definitions remained unchanged. No PHP application files, application configuration, database grants, or unrelated schedule definitions were deployed or modified.

## Schema and compatibility

Warehouse and Pharmacy received the missing APV entry/amortization tables, immutable cutover registries, GL settings, non-cash-deposit tables, source fields/indexes, and journal `LONGTEXT` remarks. Required compatibility fields and defaults on legacy GL identifiers were installed without changing existing row values or deleting legacy columns.

Production history quantities were already `DECIMAL(13,2)` and were not altered. New APV/direct-payment cutoffs were captured from production under the write hold and stored with the release evidence. No UAT records or cutoffs were copied. Existing bank mappings and historical accounting records were not populated or synchronized.

The deployment tooling now selects its environment explicitly; production commands require `--environment prod`. Production SQL and the production application's own encryption key were rendered privately. The earlier UAT release and its backups remain intact.

## Verification

- **898 isolated checks passed:** 462 inventory/blindsheet, 18 machine-run, 126 GL/journal remark, 142 updated UAT installation/recovery, and 150 production schema-copy/recovery checks.
- Production fixtures used fresh schema-only exports, synthetic records, and a fixture-only key. They covered fractional/zero valuation, repeat installation/posting, branch isolation, APV approvals/payments, cash/card deposits, purchasing receipts, rollback, and exact restoration of legacy procedure bytes/CRLF. Census routines remained unchanged during recovery.
- All 22 required diagnostic account codes were present and unambiguous. Transactional engines, exact source schemas, routine baselines, creation metadata, release integrity, and backup capacity passed preflight.
- Installed definitions and metadata matched the release. Protected census/audit/inventory routines and all out-of-scope master/UAT definitions retained their fingerprints.
- **277 protected table snapshots covering 6,566,090 existing rows had identical before/after fingerprints.** Original-column projections covered schema additions. No live posting, backfill, rebuild, test document, or artificial email was created.
- Deployed production application credentials passed preflight and postflight checks across all seven schemas. Effective CRUD permissions were checked with nonexecuting `EXPLAIN` plans where direct grant listings were insufficient; routine execution and ledger/APV-entry reads passed. No grants were changed, and no DML was executed by the probe.
- Warehouse and Pharmacy had zero active inventory GL lines after installation, consistent with enabling future accounting without a backfill.
- Python/PHP syntax, package repeatability, source/checksum parity, and Git diff checks passed. The local older MariaDB fixture used a larger thread stack; VPS6 runtime settings were not changed.
- Authenticated HTTP checks were not performed; live verification used the deployed application runtime and configured database credentials directly.

## Maintenance, backups, and recovery

Exact affected table data, all table/routine/trigger definitions, raw legacy procedure snapshots, frozen cutoffs, and historical fingerprints were saved privately before installation. Shared-server writes and privileged scheduled writers were briefly protected while applying and verifying SQL.

Original settings were restored and independently confirmed:

- Database `read_only=0`.
- Event scheduler `OFF`.
- VPS6 cron service `active`.

Backup/recovery path on VPS6:

```text
/root/weighted_cost_20261015_1206/prod
```

The adjacent `package` directory contains the private rendered release and integrity manifest. Recovery evidence includes `inspection.json`, exact definition/data dumps, before/after fingerprints, `verified.json`, maintenance snapshots, and `RECOVERY.txt`.

The installer restores replaced procedures, removes newly introduced hooks, and validates recovery before reopening writes if installation fails. Compatible schema additions remain. Never restore old table dumps over subsequent legitimate business activity. Preserve raw routine bodies and original creation metadata when restoring the legacy direct-payment procedures.

## Release hashes

Canonical `gl_post_item_history()` body SHA-256, identical in all seven production schemas:

```text
675762cd99513060e4bf2eb3685356c35fa83f36ac9792c6c3b7ad3736d40b7f
```

Private production release manifest SHA-256:

```text
ac0321b23bcf2448dedb0898d1ba90f91260f293de6bd860eb9bd3c0b6790324
```

Prerequisite SQL SHA-256:

```text
339090d13989fd9111fa7e105e58a1e72a2402f606faba99ae23b9b847635b4b
```

Existing accounting values and remarks change only through their ordinary posting/reposting lifecycle. This deployment did not rebuild historical accounting.
