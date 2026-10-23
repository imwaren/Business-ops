# Alphanumeric batch deployment — October 16, 2026

## Result

UAT was converted and verified before production. Both deployments succeeded. This was a schema-only release: existing application PHP files and environment configuration were preserved, with deployed methods compared against the local release and unchanged file hashes checked afterward.

All **34 existing batch columns across 16 schemas** are now nullable `VARCHAR(255)` with case-sensitive `utf8mb4_bin` collation. Parent receipt-item CSV fields were standardized; expiry-row and master transfer-line integer columns were converted to text. Existing values, nulls, defaults, comments, indexes and unrelated columns were preserved. No missing business tables or unrelated objects were created.

Both environments cover master, Mobile, Oakridge, Mactan, Consolacion, Warehouse, Pharmacy and Mantlewood. Transfer tables exist only in master. Each environment's installer ran twice per schema to verify repeatability.

## Verification

- Local baseline: **97 tests / 635 assertions**, including isolated migration preservation and repeatability checks. PHP syntax, Python syntax, Postman JSON/variables/examples, and diff checks passed.
- UAT: **17 columns**, **105 protected tables / 954,489 historical rows** unchanged; **166 read-only application checks** passed using PHP 8.2 and the application's actual database credentials.
- Production: **17 columns**, **105 protected tables / 3,630,988 historical rows** unchanged; **164 read-only application checks** passed using PHP 8.3 and the application's actual database credentials.
- Protected data included receipt and transfer records, inventory, item costs/conversions/history, GL, journal headers and journal items. Streaming SHA-256 comparisons included batch values using the original column projections. Integer values and their preserved text representations serialize identically in these checks.
- All existing indexes and global routine/trigger definition fingerprints remained unchanged. Separate column-metadata checks confirmed preserved defaults/comments/lengths and all unrelated columns.
- Final authenticated HTTP checks passed in **both environments** using the known requester's available existing credentials without printing tokens or records: filter lists returned 200; report/batch requests returned valid empty-result 404 JSON; invalid batch/date inputs returned 400. Production credentials were initially unavailable and became available for the final check; no login or credential change was performed by the rollout.
- Both environments returned OPTIONS 204 with `Origin-API-Key` allowed, and unauthenticated GET 401. No new critical/query/class errors were detected in the checked logs.

The read-only probes cover receipt GET fields, distinct filter lists, string batches, seven branch connections, expiry cutoffs, report filters/counts/pagination and transfer GET. They do not create test receipts, transfers or accounting records. No historical reassignment, stock correction, posting procedure or rebuild was executed.

## Restored settings

Both applications were briefly held for each shared-database write hold. Their exact `.htaccess` contents, ownership and permissions were restored. nginx, Apache, PHP 8.2-FPM, PHP 8.3-FPM and application/database cron are active. Database `read_only=0` and `event_scheduler=OFF` match their original settings. Configuration and schedule-file hashes are unchanged.

## Backups and recovery

On **both VPS5 and VPS6**:

- UAT: `/root/batch_codes_20261016_144030/uat`
- Production: `/root/batch_codes_20261016_144030/prod`

Private backups retain affected batch-table data, exact table/routine/trigger definitions, index and column metadata, original PHP hashes/files, environment-file copies, routing metadata, maintenance states and protected-data fingerprints. Deployment scripts and recovery instructions reside in the parent directory. Data dumps and secrets remain on the servers outside version control.

DDL is not transactional. Retain compatible text columns during recovery; never narrow populated text back to integers or restore a dump over subsequent activity. For an interrupted rollout, protect both applications and shared-server writers, inspect private errors, remove only an abandoned `install_receipt_batches` procedure after confirming its baseline absence, and verify protected data/definitions before resuming. `recover` intentionally refuses to reopen writes until all target columns are compatible text; complete a safe conversion or investigate under the hold. This release did not replace application PHP, so no PHP rollback was required.

## Artifacts and hashes

- [Postman Collection v2.1](../postman/Receipt_notes_transfers.postman_collection.json): **19 requests / 51 illustrative saved responses**, blank credentials, UAT/production URLs and no execution scripts.
- [Deployment manifest](batch_codes_20261016.json): preserved PHP hashes, SQL definition/fingerprint digests, verification counts, HTTP results, restored settings and backup locations.
- SQL installer SHA-256: `d0d8434f046fdcd739e3a5fa82c44343be991f6297f057f3b64ba2ecde580fb6`.
- Postman SHA-256: `872ba313b2b578020b20e79838998f6be0f507e494bd23ed0947ade259739f97`.

Batch codes preserve case and leading zeros; `AB123`, `ab123`, `0012` and `12` are distinct. Previously lost zeros from historical integer storage cannot be reconstructed. New examples use quoted string batches.
