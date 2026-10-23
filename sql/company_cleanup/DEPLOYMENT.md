# Production deployment — October 8, 2026

Completed on VPS6 across `mytemr_master` and the five production diagnostic schemas. UAT, warehouse, pharmacy, and EMR schemas were not changed.

## Verified result

- All 221 retained company rows and their 221 customer-account rows are active.
- All 245 mapped duplicate company rows and their 245 customer-account rows are soft-deleted.
- All 122 reference-column checks returned zero remaining mapped duplicate references. `customer_account_id` was remapped as a company ID, as confirmed by the user.
- Twenty conflicting duplicate names received ` [merged #ID]`. An independent postflight found zero additional name changes projected on a repeat run.
- Retained business metadata was synchronized from the approved snapshot; creation audit fields and identifying row IDs were preserved.
- All 64 affected UPDATE triggers were restored with their exact saved definitions and original action order.
- Full row fingerprints for all 18 GL/JE/journal-item tables match the pre-cleanup baseline.
- Global settings were restored to their original values: `read_only=0`, `event_scheduler=OFF`. Application writes resumed.

## Recovery locations on VPS6

- Release: `/root/company_cleanup_20261008_release/company_cleanup/`
- Deployment artifacts: `/root/company_cleanup_prod_20261008/`
- Six compressed affected-table backups: `/root/company_cleanup_prod_20261008/*.affected_tables.sql.gz`
- Backup table inventory, sizes, and SHA-256 checksums: `backup_manifest.json`
- Saved global settings: `maintenance_before.json`
- Saved triggers and restoration SQL: `apply/triggers.json`, `apply/restore_triggers.sql`
- Accounting fingerprints: `apply/ledger_before.json`, `apply/ledger_after.json`
- Reference and metadata results: `verified_postflight.txt`
- Final verified success: `SUCCESS.json`
- Execution logs: `/root/company_cleanup_20261008_release/deploy.log` and `recovery.log`

The transaction committed on the first production apply. The MariaDB client initially stripped comment lines from five recreated trigger definitions, causing exact-text verification to stop with maintenance still enabled. Recovery verified that only comment lines differed, then recreated all UPDATE hooks on those affected tables using `--comments`, retaining original order. Exact definitions, accounting fingerprints, references, and global settings were subsequently verified before service resumed. Future wrapper runs now preserve SQL comments directly.

Restoring table backups would reverse business data. It is not part of normal trigger recovery and must be performed under a new maintenance window with the accounting consequences reviewed.
