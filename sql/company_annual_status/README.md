# Annual company account status

## Policy

On January 1 at **00:05 Asia/Manila**, each environment evaluates its previous calendar year. Any master `request` with `is_deleted=0` and `added_on` inside that year qualifies, regardless of pending/approved/archived/returned/disapproved status. Match only `request.company_id` to `company.id`.

Non-deleted companies without qualifying requests become inactive in both `account_status` and `accreditation_status`. Companies with no requests ever are included; recently created companies have no grace period. Existing inactive accounts with inconsistent accreditation are also repaired. Companies with qualifying requests are not automatically activated.

The `trg_company_status_bi` and `trg_company_status_bu` BEFORE triggers force inactive accreditation whenever the new account status is inactive, including conflicting direct SQL updates. Setting accreditation active alone cannot reactivate an inactive account. Quotation approval explicitly sets **both** statuses active in its existing transaction. Installation changes no existing rows; future INSERT/UPDATE operations enforce the rule.

## Commands and schedule

```sh
php spark companies:annual-status --dry-run
php spark companies:annual-status --dry-run --year 2025
```

The dry-run reports the selected year, inclusive start/exclusive end, and matched count without writing statuses. Omitting `--dry-run` executes the operation. Use an explicit completed `--year` for recovery after a missed annual run. No write-mode command was run during deployment.

Cron expression: `5 0 1 1 *`. The schedule runs annually on January 1, including weekends. Both application server timezones were verified as Asia/Manila. Per-environment `flock` and master-connection named locks prevent overlapping job instances. SQL updates only changed rows, with `updated_by=NULL` for system work and the cleanup timestamp in `updated_on`. The command logs failures and returns a nonzero exit status.

Installed files:

- `/etc/cron.d/accounting-company-annual-status-uat`
- `/etc/cron.d/accounting-company-annual-status-prod`

Worker logs are under each API root's `writable/logs/company-annual-status-worker.log`. The first scheduled run after this October 9, 2026 rollout is **January 1, 2027**, evaluating `[2026-01-01, 2027-01-01)`.

## Current cleanup SQL — manual only

- `cleanup_uat.sql` selects `mytemr_master_uat`.
- `cleanup_prod.sql` selects `mytemr_master`.

Both evaluate **2025**, `[2025-01-01, 2026-01-01)`, as confirmed. Each contains an affected-ID preview, count, transactional UPDATE, and postflight counts. Review the preview before executing the transaction. If running interactively and postflight checks fail, issue ROLLBACK instead of COMMIT. These files have **not** been executed during rollout. They deactivate/repair only; they do not activate companies with requests.

No customer-account IDs or identifying metadata are changed. Existing company audit and wallet triggers remain installed. UAT's existing audit trigger may append trail records when status changes are subsequently executed.

## Verification and recovery

`tests/company_annual_status_check.php` uses the disposable local socket fixture only. It covers calendar boundaries, all request statuses, deleted rows, empty history, synchronization, audit/wallet coexistence, quotation-style activation, transaction rollback, and repeat execution.

Recovery/evidence directory on VPS5 and VPS6: `/root/company_annual_status_20261009_1605/{uat,prod}`. VPS5 has file backups, dry-run results, schedule/checksum evidence. VPS6 has company data/trigger backups and before/after historical fingerprints. Restore API files and the previous annual cron definition (or remove the newly installed cron if absent before rollout); use `restore_status_triggers.sql` to restore only the two scoped status triggers. Preserve unrelated schedules and company triggers.

Only the Company model, new annual CLI command, and narrow quotation activation change are deployed. The earlier local-only duplicate-name fix in `Companies.php` is excluded, and production's existing quotation-controller differences are preserved.
