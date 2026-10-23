# Weighted-cost accounting — UAT deployment, October 15, 2026

## Result

Deployment and verification completed on VPS6 for all seven UAT inventory/diagnostic schemas:

- Mobile (`1`): `mytemr_diagnostic_mobile_uat`
- Oakridge (`2`): `mytemr_diagnostic_uat`
- Mactan (`3`): `mytemr_diagnostic_mactan_uat`
- Consolacion (`4`): `mytemr_diagnostic_conso_uat`
- Mantlewood (`7`): `mytemr_diagnostic_mantlewood_uat`
- Oakridge Warehouse (`5`): `mytemr_oakridge_warehouse_uat`
- Oakridge Pharmacy (`6`): `mytemr_oakridge_pharmacy_uat`

Only `gl_post_item_history()` was replaced in each of the five clinics. Warehouse and Pharmacy each received 108 current diagnostic accounting objects: 34 functions, 28 procedures, and 46 triggers. Backfill procedures were excluded.

All seven inventory posters now value eligible movements as `ABS(item_history.quantity) * IFNULL(master.item.weighted_cost, 0)`, without a purchase-cost fallback. Existing account mappings, quantities, dates, eligibility, remarks, and journal grouping are preserved.

No PHP application files, production schema/definitions, or master accounting definitions were deployed. Existing inventory and audit triggers were preserved.

## Warehouse and Pharmacy prerequisites

Installed missing APV entry/amortization tables, immutable accounting cutoffs, GL settings, and an empty non-cash-deposit table based on the verified Oakridge UAT structure. Required GL/journal source fields and indexes were added. Journal remarks now support `LONGTEXT`.

Read-only inspection and exact-schema fixtures identified additional compatibility requirements: nullable bank payment-method mapping, worklist/deposit-date and replenishment linkage fields, and defaults on the three mandatory legacy GL identifiers omitted by source-based inserts. Existing legacy fields and values were retained.

Both schemas had integer `item_history.quantity` columns. These were widened to `DECIMAL(13,2)` so future fractional movements are retained. Existing values were compared using the same decimal projection before and after widening; no historical quantity corrections were made.

New APV cutoffs were captured under the write hold and saved with the release evidence. They must not advance on redeployment. Existing bank mapping values were not populated or synchronized from another branch.

## Verification

- **746 isolated checks passed:** 462 inventory/blindsheet checks, 18 machine-run checks, 126 GL/journal remark checks, and 140 full Warehouse/Pharmacy installation checks.
- The additional fixture used fresh UAT schema-only exports and synthetic records. It exercised strict-mode GL inserts, fractional valuation, repeated installation/posting, APV approvals/payments and legacy preservation, cash/card payments, deposits, purchasing receipts, branch identity, rollback, and definition recovery.
- The older local MariaDB 10.4 fixture runtime needed a larger thread stack for nested card/deposit triggers. VPS6's MariaDB 11.4 runtime configuration was not changed.
- All 22 diagnostic account codes were present and unambiguous in UAT master.
- Every installed definition and its creation metadata matched the private release. Protected audit/inventory definitions and all out-of-scope master/production definitions remained unchanged.
- **277 protected table snapshots, containing 1,324,085 existing rows, retained matching before/after fingerprints.** Original-column projections were used for additive schema changes.
- Newly created accounting child/deposit tables remained empty during installation. No live financial posting, backfill, rebuild, test document, or artificial email was created.
- Read-only application checks ran through the deployed VPS5 UAT runtime with its configured credentials. All seven schema identities, permissions, function execution, and ledger reads passed. Warehouse and Pharmacy still had zero active inventory GL lines after installation, as expected without a backfill.
- PHP/Python syntax and Git diff checks passed. No authenticated HTTP request was made; application verification used the deployed database credentials directly.

## Maintenance and recovery

Exact affected data and definitions were backed up before DDL under a protected write window. The original settings were restored and independently checked:

- Database `read_only=0`.
- Event scheduler `OFF`.
- VPS6 cron service `active`.

The server-wide hold briefly protected shared-server writes; production received no schema or routine changes. Unrelated schedule definitions and application configuration were not edited.

Private backup/recovery location on VPS6:

```text
/root/weighted_cost_20261015_1132/uat
```

This contains exact table data/definitions, scoped routine snapshots, before/after fingerprints, frozen cutoffs, maintenance state, verification results, and `RECOVERY.txt`. The private rendered package is in the adjacent `package` directory. Do not commit these files or restore old table data over newer business activity.

The installer automatically restores replaced clinic procedures and removes newly introduced Warehouse/Pharmacy accounting hooks if validation fails. Compatible schema additions remain. Recovery verification must pass before reopening writes.

## Release hashes

Canonical `gl_post_item_history()` body SHA-256, identical in all seven schemas:

```text
902e7e9dd4f4e78df6f24d39b8092e29f92ec05c45c03d9772022a7400ac3d6a
```

Private release manifest SHA-256:

```text
c5efb20c4de3986bfb4bb70aaf431c08cbc9c93af9df5b5c5af17020a70a7373
```

Prerequisite SQL SHA-256:

```text
c70d0fc60d62d97dcee4eb6a6329e6de34424136f51d5f234dedd6bbe6403866
```

Existing accounting amounts and remarks change only through their ordinary source posting/reposting lifecycle; this release performed no historical accounting update.
