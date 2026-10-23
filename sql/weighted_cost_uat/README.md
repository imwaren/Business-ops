# Weighted-cost accounting releases

This package updates only the inventory-history poster in the five existing clinic schemas and installs full diagnostic accounting in Warehouse (5) and Pharmacy (6). Select `uat` or `prod` explicitly for production releases. It never deploys PHP application files, changes master/other-environment routines, or calls a financial poster/backfill during installation.

## Preparation

Keep all generated files outside the checkout, in a private directory. Obtain schema-only, trigger-free exports named `master_schema.sql`, `oakridge_schema.sql`, `warehouse_schema.sql`, and `pharmacy_schema.sql` in that directory. They must come from the selected environment. Production additionally requires private `legacy_objects.json` snapshots of the existing direct-payment and two census procedures in each of Warehouse and Pharmacy. Preserve raw routine bodies, creation metadata, and CRLF line endings. The builder checks the clinic change against the current Git baseline, so inspect that baseline before preparing a later release.

```sh
python3 -B sql/weighted_cost_uat/build_release.py /private/tmp/RELEASE/package
python3 -B tests/weighted_cost_uat_install_check.py /private/tmp/RELEASE
```

For production, build with `--environment prod`. The default remains `uat` for compatibility. Existing decimal quantities are not altered; a mixed/unexpected quantity schema requires review. The production fixture restores the actual reviewed legacy procedure byte-for-byte and verifies both census routines remain unchanged.

The fixture uses the socket-only local MariaDB instance at `/private/tmp/items_blindsheet_fixture/mysql.sock`, synthetic data, and a fixture-only key. Local MariaDB 10.4 needs a larger thread stack for the existing nested payment/deposit routines; the deployment does not modify VPS6 runtime settings. The fixture translates the newer dump sandbox directive and unsupported server-default collation for this older local runtime.

Render the key placeholder in the private manifest from the selected environment's configured application key, never from a session variable. Refresh `checksums.json` after rendering and stage the complete private package on VPS6. Never log keys, stored helper definitions, or decrypted names. Do not commit rendered packages or backups, and do not overwrite earlier UAT release evidence when preparing production.

## Installation

The root-only installer supports:

```sh
python3 -B /root/RELEASE/package/deploy.py inspect
python3 -B /root/RELEASE/package/deploy.py deploy
python3 -B /root/RELEASE/package/deploy.py verify
```

Append `--environment prod` to production commands. A production manifest is rejected when the environment argument is omitted. Environment selection controls schema names, master catalog references, snapshot directories, and baseline checks; it is not inferred from the presence of files.

It rejects schema/definition drift, verifies mappings and transactional engines, protects writes, pauses VPS6 cron/event writers, backs up source/accounting tables and exact definitions, and checks original-column fingerprints before restoring maintenance settings. The other environment shares the server's brief write hold but receives no schema or definition changes. The deployed application permissions are checked separately with `scripts/weighted_cost_uat_probe.php`; run that probe privately through the matching deployed runtime, without placing it in public application paths. Its production argument is `--environment=prod`; `--before` skips functions that are not yet installed. If direct grant listings are insufficient, the probe checks effective CRUD access with nonexecuting `EXPLAIN` statements and checks read-only routine execution. No data-changing statement is executed and no grants are modified.

Warehouse/Pharmacy prerequisites retain legacy GL columns and set defaults for the three legacy identifiers omitted by source-based inserts. They add required source fields, nullable bank/payment and source links, immutable APV cutoffs, and missing APV/non-cash tables. Journal remarks become `LONGTEXT`; integer inventory-history quantities widen to `DECIMAL(13,2)`. Existing values are preserved. Quantity fingerprints use the same decimal projection before and after widening.

## Recovery

On failure, the installer restores replaced clinic and reviewed legacy procedures, removes introduced Warehouse/Pharmacy routines and triggers, verifies protected definitions and historical fingerprints, and restores original maintenance settings. Exact raw routine bodies avoid XML's CRLF normalization and preserve labels. Compatible schema additions remain. If recovery validation fails, the write hold stays enabled and cron stays paused; consult `RECOVERY.txt`, the private SQL error file, and the saved snapshots before resuming writers. Never reload old table dumps over newer business data.

The immutable cutover registry is never advanced by redeployment. Use a new release directory for retries; preserve the original failed-run evidence. Existing accounting remarks and amounts are not rewritten by this deployment. Ordinary future postings use weighted cost; ordinary reposting follows the existing source lifecycle.
