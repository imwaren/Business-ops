# Item 4847, Oakridge UAT machine-run repair

Completed 2026-10-14 (Asia/Manila). Scope: `mytemr_diagnostic_uat`, item 4847, branch 2 only.

## Cause and correction

The old inventory trigger declared `qty_diff INT`, rounding two 0.05 inventory movements to zero. The installed UAT trigger already uses `DECIMAL(13,2)`; it was verified and left unchanged. The root `item_history_trigger.sql` declaration now matches it for future installations.

History rows 23391 (purchase) and 23392 (clinic) were corrected from 0.00 to 0.05. Row 23393 (usage) remains 50.00. Correction audit uses a null system updater and the correction timestamp. Stock, original source/activity IDs, dates, creators, and beginning-inventory rows were preserved.

The existing history-update trigger posted clinic row 23392 automatically. No second procedure call followed its update. Purchase and usage rows have no accounting movements.

## Accounting result

- GL 3893: debit account 1109, `500-205` Laboratory Supplies, 0.2500.
- GL 3894: credit account 137, `104-030` Laboratory Inventory, 0.2500.
- Approved journal 1316; items 22983 and 22984 match the GL.
- Financial date: 2026-10-13; source creator: 9; cost: 5.0000.

## Verification

18 isolated checks passed using synthetic records on a socket-only disposable MariaDB. These covered actual history/posting hooks, fractional quantities, clinic-only posting, rollback after posting, repeat execution with unchanged audit/history, corrected-history/missing-posting recovery, cost drift, and rejection of unexpected accounting.

Live checks validated frozen previous/current stock snapshots, conversions, accounts, cost trail, InnoDB dependencies, and exact installed trigger/poster definitions. All source locks, updates, GL/JE validation, and before/after fingerprints used one database connection and transaction.

Fingerprints of all stock, out-of-scope history/GL/journals/items, conversions, cost trails, catalogs, accounts, and protected target columns matched before commit and after commit. SQL definitions were unchanged. The final read-only preview reports complete accounting. No live test records were created.

Original maintenance was restored: `read_only=0`, event scheduler `OFF`, cron active. No PHP or production changes were made.

## Helper and private backups

Helper: `scripts/repairs/machine_run_4847_uat.py`. Live database selection is frozen to Oakridge UAT/master UAT; only explicitly named disposable fixture schemas may override it. It never updates stock or installs SQL.

VPS6 backup root: `/root/machine_run_4847_20261014_0017/uat`.

- `preview/`: initial read-only preflight.
- `repair/`: exact schema/routine/trigger definitions, scoped history/stock/GL/JE dumps, maintenance settings, before/in-transaction/after fingerprints, result IDs, and recovery instructions.
- `postflight/`: final read-only preview.

Files are private (root directory mode 0700; files 0600) and excluded from version control because definitions may contain environment secrets.

Preview command on VPS6:

```sh
python3 /root/machine_run_4847_uat.py preview --output /root/machine_run_4847_<timestamp>/uat/preview
```

Apply uses `apply` with a fresh output directory. It validates all frozen inputs again, pauses privileged scheduled writers, enables database read-only protection for ordinary accounts, drains transactions, backs up definitions/rows, and acquires target locks. Reruns do not modify already-correct quantities or complete postings. Already-correct quantities with absent accounting permit one explicit clinic-only repost; unexpected accounting stops execution.

Before commit, failures roll back. A failed recovery/postcommit check retains the maintenance hold for inspection. Never reload snapshot dumps over newer business data. No unrestricted backfill or rebuild is included.
