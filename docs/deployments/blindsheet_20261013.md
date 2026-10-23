# Blindsheet deployment — October 13, 2026

## Final result

UAT was deployed and verified before production. Both environments now run the same release. Normal HTTP routing, database writes, and both servers' cron services are restored. The database event scheduler remains OFF, matching its original setting.

Only these application files were deployed on VPS5:

- `app/Controllers/Items.php`
- `app/Models/Branch_item_blindsheet.php`

Configuration, normal API keys, environment-specific traits, other application files, and scheduled job definitions remain unchanged. No database grants were changed.

## Endpoints and documentation

Import `docs/postman/Items_blindsheet.postman_collection.json` into Postman. Collection v2.1 contains seven requests and 20 illustrative saved responses, with blank credentials and no execution scripts.

- `GET /items/get_blindsheet`
- `POST /items/blindsheet`
- `POST /items/blindsheet_change_status`

UAT: `https://zuat-accounting-back.myt-emr.com`

Production: `https://accounting-back.myt-emr.com`

Use the normal `API-KEY` header. Ordinary callers use master login and existing permissions. `origin=csr` requires an active, unexpired user token from the selected branch. Supported inventory branches are 1, 2, 3, 4, 5, 6, and 7; master 0 is a mirror/audit source, not a selectable inventory branch.

The collection documents filters, pagination, audit sources, zero/increased/decreased/fractional counts, pending-only transitions, stale-count rejection, and recovery from partial mirror failures. Successful submission creates a pending count; only approval changes stock.

## Database coverage

Additive schema, four nullable audit-source columns, two lookup indexes, and the fractional inventory-history trigger were installed in all eight schemas per environment:

- Master: `mytemr_master`
- Mobile: `mytemr_diagnostic_mobile`
- Oakridge: `mytemr_diagnostic`
- Mactan: `mytemr_diagnostic_mactan`
- Consolacion: `mytemr_diagnostic_conso`
- Warehouse: `mytemr_oakridge_warehouse`
- Pharmacy: `mytemr_oakridge_pharmacy`
- Mantlewood: `mytemr_diagnostic_mantlewood`

UAT uses the corresponding `_uat` suffix on each schema.

The four scoped accounting objects were installed only in clinics 1, 2, 3, 4, and 7: `gl_item_history_remarks`, `gl_post_item_history`, `trg_gl_item_history_ai`, and `trg_gl_item_history_au`. Warehouse and Pharmacy remain inventory-only. Master accounting routines and unrelated audit/posting definitions remain unchanged. Trigger order, definers, SQL modes, connection character sets, and saved database collations were verified.

## Verification

- 296 isolated fixture checks passed, including authentication, zero/fractional counts, concurrent operations, stale counts, mirror failures, stock/cost/history, GL/JE, repeatable migrations, and both diagnostic SQL variants.
- Each environment passed 34 live read-only application/model checks with its actual database credentials. Production also passed 33 held checks before full runtime readiness verification.
- Deployed PHP passed syntax checks under PHP 8.2 and 8.3, autoloading checks, ownership/permission checks, and matching SHA-256 checksums. FPM was gracefully reloaded for opcode caching.
- Both unauthenticated GET checks returned HTTP 401 in each environment: blindsheet listing and CSR item listing.
- No new blindsheet errors were found after final verification. Production's final log window had no new application errors; UAT's wider log window contained five unrelated error lines.
- Authenticated HTTP response checks were unavailable because no valid current credentials were supplied. The application/model checks used real configured database connections; endpoint authentication was covered by isolated fixtures and unauthenticated HTTP checks.
- Postman JSON, variables, multipart bodies, saved response JSON, blank credentials, and absence of execution scripts passed validation. PHP syntax and working-tree diff checks passed.

## Historical preservation

The deployment compared complete row counts and SHA-256 fingerprints for 70 existing table snapshots in each environment, covering blindsheets, branch inventory, catalog items, conversion/cost data, inventory history, GL, journal headers, and journal items. Original column projections were used so additive columns did not invalidate comparisons.

All before/after fingerprints matched: 952,258 rows in UAT and 3,628,238 rows in production. Newly created Warehouse/Pharmacy blindsheet tables remained empty. Unrelated routine/trigger definitions and trigger execution order matched their original snapshots.

No live counts, approval submissions, accounting records, emails, historical backfills, or GL/JE rebuilds were created by this rollout. Posting changes apply to future ordinary workflows.

## Release checksums

The following full SHA-256 values match both deployed environments:

```text
app/Controllers/Items.php
dcfc294e1be11df847aa49ee74e00a3e4694b8175d0b0e4456632468d0ae8a8f

app/Models/Branch_item_blindsheet.php
4e32887333539eeb45fa88e2145a0b6cb6ad9a57149466183f0cc4c1eeec81db

schema.sql
4f2c55e99d8660879d8748a653cd27b8fc3ab13522254fa62c95abc78ba5d094

inventory_history.sql
513546d47d9d2ede30e42c42d00a496905ee2a45655c677edacec7864d4f6b67

accounting_uat.sql
054d16b7740f244f29df09dd798602d7002cf5adc48490368a09c62df6612e69

accounting_prod.sql
3789901da0ffd94488ae1f16a857f53eb3d7d018a2c745b0de4483c008df49ff
```

## Backup and recovery locations

Both VPS5 and VPS6 retain private backups under:

```text
/root/blindsheet_20261013_202203/uat
/root/blindsheet_20261013_202203/prod
```

VPS5 retains original deployed files, ownership/modes, routing, configuration/schedule fingerprints, and additional UAT readiness/key-compatibility revision backups. VPS6 retains affected table data/definitions, all routine/trigger definitions, exact scoped definitions/settings, trigger ordering, original column maps, and before/after fingerprints. Private recovery instructions are in each environment's `RECOVERY.txt`.

Earlier verification failures were recovered before retrying: previous PHP and exact SQL definitions were restored, historical fingerprints were rechecked, and service settings were restored. Corrections preserved trigger comments/line endings and historical collation metadata; fixed multiline metadata parsing; checked effective permissions without modifying grants; and handled production's missing `myt_env()` dependency inside Items only.

MariaDB blocks `EXPLAIN INSERT/UPDATE` under its global read-only hold. Effective permissions were therefore checked before pausing writes; code/model/schema/history checks ran under the hold; full runtime readiness ran after database protection was restored and before reopening the API.

For a later recovery, pause affected writers first, restore the previous controller before removing the new model, restore exact scoped SQL definitions, retain compatible additive schema, and validate current data before reopening writes. Do not restore old table dumps over subsequent legitimate business activity.

Mirroring follows adjustment's separate-connection behavior. A late operation failure can leave partial mirrors; reconcile affected blindsheet, stock, cost, history, and source-branch accounting records before retrying. The Postman collection explains this limitation.
