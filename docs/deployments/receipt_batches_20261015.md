# Receipt batches and transfer deployment — October 15, 2026

## Result

UAT was installed and verified before production. Both deployments succeeded. The nine scoped PHP files were deployed on VPS5; additive SQL was installed twice per schema on VPS6 to verify repeatability. No business documents, inventory corrections, historical batch assignments, or accounting rebuilds were executed.

## Coverage and verification

- **UAT:** master plus Mobile, Oakridge, Mactan, Consolacion, Warehouse, Pharmacy, and Mantlewood; 105 protected tables / 954,101 historical rows unchanged. Installed controller/model probe: 108 read-only checks passed.
- **Production:** master plus Mobile, Oakridge, Mactan, Consolacion, Warehouse, Pharmacy, and Mantlewood; 105 protected tables / 3,630,582 historical rows unchanged. Installed controller/model probe: 114 read-only checks passed.

- Receipt parent batches remain nullable `VARCHAR(255)`; expiry batches remain nullable `INT`. Added nullable transfer-line `INT batch_no` in each master, expiry lookup indexes in all 16 schemas, and transfer lookup indexes in both masters.
- All installed PHP SHA-256 hashes matched their environment-specific manifests; PHP 8.2/8.3 syntax checks and opcode-cache refresh passed.
- UAT retains its disabled receipt-create attachment step. Production retains its original pagination and attachment behavior. Pre-existing stock duplicates in production mirrors were left unchanged.
- All SQL routine/trigger fingerprints remained unchanged. Original-column projections were used for historical comparisons where the transfer column changed table shape.
- Targeted verification: **34 PHPUnit tests / 232 assertions**, plus **462 blindsheet checks**, SQL repeatability, and diff checks passed.
- Installed reads cover receipt batch fields across all seven branches, transfer line batches, filtered/unfiltered report queries and counts, pagination, and invalid report inputs.
- No fresh requester/token credentials were supplied for authenticated HTTP checks. Read-only controller/model probes used each deployed application's actual database credentials and suppressed API logging; HTTP authentication was not bypassed in the installed application.

## Restored service settings

Both environments' original `.htaccess`, ownership/modes, configuration, and scheduler files were preserved. Application and database cron services are active; database `read_only=0` and `event_scheduler=OFF`, matching their original states. Both PHP-FPM services are active.

## Backups and recovery

On **both VPS5 and VPS6**:

- UAT: `/root/receipt_batches_20261015_1825/uat`
- Production: `/root/receipt_batches_20261015_1825/prod`

Private backups include exact previous files, permissions, table/SQL definitions and relevant data dumps; manifests and historical fingerprints are retained alongside them. `RECOVERY.txt` and `deploy.py` are in the parent release directory. Restore previous controllers before dependencies, retain compatible additive columns/indexes, and verify historical/definition fingerprints before resuming writes. Do not restore data dumps over subsequent business activity or blindly replay a failed mirrored transfer.

## Artifacts and checksums

- [Postman Collection v2.1](../postman/Receipt_notes_transfers.postman_collection.json): **15 requests / 44 illustrative saved responses**, blank credentials, UAT/production URLs, no execution scripts.
- [Machine-readable deployment manifest](receipt_batches_20261015.json) contains all nine deployed-file hashes per environment, original hashes, schema coverage, verification counts, restored settings, and fingerprint-manifest digests.
- SQL installer SHA-256: `c1231d762d8a0a60c6c9e6a434735c66f628a091ee0f7bcd882758926d5b8b92`.
- Postman SHA-256: `a90133f10aaf50626d4fffce387b6ad440306ea311f783b7ce42607ade0ce19a`.
