# Accounting cron restoration — 2026-10-17

## Result

Deployed and verified UAT first, then production. Verification finished at 05:01 Manila time. Full installed SHA-256 hashes, HTTP results, branch counts, query readiness and next-run timestamps are recorded in [the release manifest](accounting_cron_20261017.json).

- UAT: all 10 existing accounting HTTP jobs use the private PHP 8.2 helper.
- Production: all 11 existing accounting HTTP jobs retain their helper command paths, using the updated PHP 8.3 helper.
- Both inventory snapshots are scheduled at `0 23 * * *` in Asia/Manila, in both Hestia's persistent configuration and the installed user crontab.
- Existing secret values and private application configuration were preserved. Secrets are loaded from each application's `.env` and sent only in `X-Cron-Secret`.
- Eight missing sales/TAT methods were restored from Git baseline `ccc6479^`, preserving calculations and environment-backed database resolution. Only these methods were inserted into each deployed `Report.php`.
- Both domains load `ProxyTimeout 300` through their supported Apache HTTP/HTTPS includes. Apache configuration validation passed. Global `Timeout 30`, nginx settings and unrelated domains remain unchanged.
- Only affected scheduler entries were paused during installation; all were restored. File ownership/permissions, unrelated scheduler lines and service states passed final verification.

## Observed live execution

Exactly one manual `snapshot_item_inventory` request was made per environment for **2026-10-17**. Neither request was retried.

- UAT: HTTP 200, 64.756 seconds, 566 unique daily rows in each of Mobile, Oakridge, Mactan, Consolacion, Warehouse, Pharmacy and Mantlewood; **3,962 total**.
- Production: HTTP 200, 48.233 seconds. Unique daily rows: Mobile 583; Oakridge 581; Mactan 581; Consolacion 581; Warehouse 19; Pharmacy 40; Mantlewood 581; **2,966 total**.
- Production's existing Mantlewood source query contains one repeated item. The snapshot reported 582 processed source rows and upserted 581 unique daily keys. That input duplication was preserved; no duplicate daily key was created.
- Both environments have seven verified branch snapshots, no duplicate current-date daily keys, and matching branch/master monthly quantity and ending-cost rollups.
- Daily records outside October 17 and monthly records outside October retained their pre-run fingerprints.

Missing and invalid cron secrets returned HTTP 401 in both environments. An authenticated nonexistent method reached `PageNotFoundException`; the existing bootstrap rendered it as HTTP 500 rather than 404. This existing error-rendering behavior was preserved. The actual snapshot requests returned HTTP 200.

## Verification scope

- Local expanded regression suite: **139 tests / 846 assertions passed**, covering cron, security, CORS, error handling and related controller regressions.
- Final focused cron/security run: **21 tests / 129 assertions passed**.
- Helper fixtures covered environment isolation, header credentials, restricted HTTPS targets, overlap protection, redacted failures and snapshot-response validation.
- All eight restored report queries passed mocks and live read-only `EXPLAIN` checks using configured application connections in each environment.
- PHP syntax, Python syntax, Apache configuration, installed checksums, source-schema selection, application-user readability and diff checks passed.
- Final scheduler verification accepted both recorded environment releases and checked all unrelated entries for drift.

Sales/TAT and other business jobs were **not manually executed**. Their readiness was checked using mocks, authentication and query planning; successful scheduled execution has not yet been observed by this rollout.

## Next scheduled runs

Times below were calculated from the final installed schedules at verification time; all are Asia/Manila.

### UAT

- October 17, 22:00: `get_medrecord`, `get_daily_sales`, `get_patient_tat`, `get_daily_census`.
- October 17, 23:00: `snapshot_item_inventory`.
- October 18, 00:00: `post_direct_payment_journal_entries`.
- October 18, 01:00: `update_validity_date_range_status`, `get_sales_census`, `get_service_census`, `get_inter_branch_census`.

### Production

- October 17, 06:00, then hourly: `refresh_uip`.
- October 17, 22:00: `get_daily_sales`, `get_patient_tat`, `get_daily_census`.
- October 17, 23:00: `get_medrecord`, `snapshot_item_inventory`.
- October 18, 00:00: `post_direct_payment_journal_entries`.
- October 18, 00:01: `update_validity_date_range_status`.
- October 18, 01:00: `get_sales_census`, `get_service_census`, `get_inter_branch_census`.

## Backups and recovery

Private backup root on **VPS5 and VPS6**:

```text
/root/accounting_cron_20261017_044850/uat
/root/accounting_cron_20261017_044850/prod
```

VPS5 contains exact original model/helper files where present, configuration backups, original and installed scheduler definitions, ownership/permission metadata, staged files, hashes, validation results, and current-date/current-month snapshot records. New Apache includes are recorded as previously absent. VPS6 contains corresponding snapshot-record backups and exact daily/monthly table structures for all eight configured schemas per environment.

Deployment tooling is retained privately at `/root/accounting_cron_20261017_044850/deploy.py`. Its `recover` operation restores the recorded files and scheduler definitions, tests Apache configuration, and reloads affected services. If both environments require recovery, restore production before UAT so shared scheduler backups remain consistent. Verify recovered files against original backup hashes rather than release hashes.

Before any recovery or snapshot retry, pause the affected scheduled entries and inspect for running requests and partially completed snapshots. Do not restore snapshot dumps over subsequent activity. A successful current-date snapshot should not be repeated as a deployment check.

## Boundaries

No historical snapshot backfill, stock correction, accounting rebuild, SQL trigger/schema changes, credential rotation, other-domain changes, or unrelated application deployment. Existing CLI jobs were preserved. Unrelated pending local transfer/report changes were not deployed.

For helper setup and safe operational commands, see [the Hestia deployment guide](../HESTIA_DEPLOY.md#accounting-cron-jobs).
