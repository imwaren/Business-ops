# Company cleanup — production SQL for manual VPS6 execution

This package is the production cleanup release. Production execution uses `deploy_cleanup.py` for maintenance, backups, cleanup, and restoration; see [DEPLOYMENT.md](DEPLOYMENT.md) for the completed production result and recovery paths.

It targets `mytemr_master`, `mytemr_diagnostic_mobile`, `mytemr_diagnostic`, `mytemr_diagnostic_mactan`, `mytemr_diagnostic_conso`, and `mytemr_diagnostic_mantlewood`. Warehouse, pharmacy, EMR, UAT, and staging dump tables are outside this cleanup.

## Files and approved mapping

- `00_setup.sql`: temporary snapshot of all 221 rows from the supplied `company_dump.sql`, 245 explicit approved company mappings, used for every company-reference field, including customer_account_id. No import into the live company_dump table is necessary.
- `01_preflight.sql`: mapping validation, company/customer existence checks, projected uniqueness checks, planned duplicate-name suffixes, and affected-row counts.
- `02_cleanup.sql`: one InnoDB transaction with a rollback handler, exact scalar/token updates, duplicate soft-deletion, retained metadata synchronization, and post-update assertions before commit.
- `03_postflight.sql`: retained company/customer data and remaining-reference counts, expected to be zero.
- `reference_inventory.json`: the explicit 122 schema/table/column references included in the cleanup.
- `run_cleanup.py`: optional orchestration wrapper, required for safely suspending/restoring live UPDATE triggers during application of these SQL files.

Confirmed exceptions: 3471 maps only to 3472; 96 and 117 both remain, with 21 mapped to 117; 3710's self-reference is ignored. Canonical IDs never also appear as duplicate IDs.

Business metadata is copied exactly, including null and empty values. Empty `account_id`/`bank_id` become SQL NULL; other integer values must be valid nonnegative signed INTs. Creation audit values and existing `updated_by` remain unchanged. `updated_on` records the run timestamp. Duplicate company/customer rows retain their original IDs and company linkage and finish deleted; retained rows finish active.

## Collision handling and customer-reference semantics

The supplied company data contains 20 projected collisions on `company(name, account_id, is_deleted)` when duplicates become deleted. Conflicting duplicate company names receive ` [merged #ID]`, truncating the original prefix to fit 100 characters. Retained metadata and existing unrelated historical rows keep their names. Only currently projected collisions are suffixed; subsequent runs do not repeat the suffix. Any remaining projected collision stops preflight.

`request`, `costing`, and `quotation.customer_account_id` are **company IDs**, as explicitly confirmed by the user. Scalar/list values use the company mapping. Customer-account row IDs are retained; rows linked to duplicate company IDs are soft-deleted, and all rows linked to retained company IDs receive the retained name and active flag. No separate account-row-ID mapping is used.

Missing retained companies/customer accounts, live schema discrepancies, or additional relevant unique indexes stop deployment before reference updates.

## Reference rules

Company foreign keys include company/HMO/charge-to references in billing, booking, diagnostics, invoices, discounts, doctor/test HMO configuration, sales documents, endorsements, wallets, portal users, and historical SOA records. Soft-deleted referencing rows are included.

Comma-separated ID values are matched as exact tokens; 15 does not match 115. Unrelated tokens and NULL remain intact. Mapping two tokens to the same survivor can leave a repeated survivor token; this package preserves list cardinality rather than deduplicating lists.

Text `charge_to` values such as `company`/`hmo`, supplier IDs, contract IDs, price fields, descriptive company-name snapshots, and all `_dump` staging tables are excluded. `charge_to_ids` and `charge_tos` are company-ID lists as used by the application. No generic numeric-ID replacement is performed. Legacy diagnostic `customer_account` tables are not the authoritative master accounts and are excluded.

## Production deployment

From the local repository root, package the files and transfer them. For recovery or a future repeat deployment, these commands transfer the release:

```sh
tar --exclude='__pycache__' -czf /tmp/company_cleanup_20261008.tar.gz -C sql company_cleanup
ssh -i /Users/myt_waren/.ssh/id_ed25519 -p 1969 root@vps6.znvme.mytdedi01.filipinowebservices.com 'test ! -e /root/company_cleanup_20261008.tar.gz && cat > /root/company_cleanup_20261008.tar.gz' < /tmp/company_cleanup_20261008.tar.gz
ssh -i /Users/myt_waren/.ssh/id_ed25519 -p 1969 root@vps6.znvme.mytdedi01.filipinowebservices.com
```

On VPS6:

```sh
cd /root
tar -xzf company_cleanup_20261008.tar.gz
cd /root/company_cleanup
python3 run_cleanup.py preview --work-dir /root/company_cleanup_preview_20261008
```

The wrapper defaults to the local `mariadb` client and its normal credential discovery. If needed, use `--mysql mysql` and `--defaults-file /root/.my.cnf`; keep passwords in a protected MySQL option file, not command arguments. The VPS6 client connection should point to the local production database server. The package requires MariaDB's anonymous `BEGIN NOT ATOMIC` blocks (the supplied server is MariaDB 11.4).

Inspect `preflight.txt` on success or `failed_query_output.txt` on failure. The latter retains printed diagnostics and the error. Fix blockers and rerun preview before proceeding.

## Apply during maintenance

1. Stop application writes, scheduled jobs, and workers for all six target databases. Suspend relevant scheduled database events too. Take your normal database backup before reference changes. Trigger DDL implicitly commits, so triggers cannot be protected by the cleanup transaction itself.
2. Run the apply command below. It performs preflight again, records all fields of every GL/JE/item row as streamed SHA-256 fingerprints, captures live affected UPDATE trigger definitions, removes those UPDATE triggers, then runs setup/preflight/cleanup/postflight in one SQL session. Cleanup rolls back on SQL/assertion failure. The wrapper restores exact trigger definitions in their original order on success, exception, or handled termination.
3. Confirm the final success message, zero remaining references, matching `ledger_before.json` and `ledger_after.json`, and restored triggers before resuming writes.

```sh
python3 run_cleanup.py apply --maintenance-confirmed --work-dir /root/company_cleanup_apply_20261008
```

Use a **new work directory** for each apply attempt to avoid overwriting trigger backups. An apply failure before commit rolls back reference changes. A failure after commit (for example trigger restoration) leaves the cleanup committed; keep maintenance enabled and restore triggers before resuming service. The wrapper prints a commit-success message only after ledger fingerprints match.

## Trigger recovery

For a failed cleanup, inspect its output and use the saved work directory:

```sh
python3 run_cleanup.py restore --work-dir /root/company_cleanup_apply_20261008
```

The restore command leaves an already installed matching trigger alone and refuses to overwrite an installed differing definition. It verifies restored definitions using `SHOW CREATE TRIGGER`.

If the Python process is forcibly killed, the host restarts, or SSH drops before recovery, keep writes stopped and run the restore command manually. `triggers.json` and `restore_triggers.sql` are saved **before** the first DROP. As a last-resort recovery, review and run `restore_triggers.sql` with the MySQL client; it restores saved SQL modes, character sets, collations, definers, and bodies. This raw restoration script expects those trigger names to be absent; the wrapper is preferred for partial restoration.

No master or diagnostic GL procedures are altered. No accounting rebuild is requested or performed.

## SQL-only usage

The maintenance wrapper `deploy_cleanup.py` manages backups/read-only settings and invokes `run_cleanup.py` for schema checks, trigger capture/restoration, and full GL/JE row fingerprints. For read-only preview with only the client, concatenate setup and preflight in the same session:

```sh
cat 00_setup.sql 01_preflight.sql | mariadb --comments --default-character-set=utf8mb4 --batch --raw > /root/company_cleanup_preview_sql_20261008.txt
```

Do **not** run `02_cleanup.sql` alone while application triggers are active. It depends on the temporary mapping tables from `00_setup.sql` and prior successful preflight. Manual execution requires the same trigger preservation and maintenance steps as the wrapper.

## Automated production maintenance wrapper

Run `python3 deploy_cleanup.py --work-dir /root/company_cleanup_prod_YYYYMMDD` only for an authorized production window. It records `maintenance_before.json`, enables server-wide read-only mode for normal accounts, pauses the event scheduler, rechecks preflight, creates six compressed affected-table backups and a `backup_manifest.json`, then applies the cleanup. On verified success it restores original global settings and creates `SUCCESS.json`.

On failure, it deliberately leaves maintenance enabled. Restore triggers using `run_cleanup.py restore --work-dir <work-dir>/apply`, inspect whether cleanup committed, compare accounting fingerprints, and restore the exact settings from `maintenance_before.json` only after recovery is verified. Root/mysql privileged accounts bypass read-only; the wrapper refuses additional privileged accounts or other active privileged sessions before proceeding. Ordinary reads continue during maintenance.
