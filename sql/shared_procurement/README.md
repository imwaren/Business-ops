# Shared-center procurement (local release)

## Behavior

Active `branch.type='shared'` owners use master for purchase requests, AOQs, purchase orders, receipt notes, purchase returns, their children/attachments, approval reminders, and linked receipt-note APVs. Procurement ownership uses the existing header `branch_id`; line/header `cost_center_id` allocations retain their existing meaning. Clinic, warehouse and pharmacy procurement routing remains available. Fund modules do not gain warehouse/pharmacy permissions.

Shared accounting uses master GL `branch_id=0` / JE `cost_center_id=0`. The existing clinic RN inventory/VAT/transitory rules and per-item source grouping are reused. PR/PO hooks retain their existing clearing lifecycle; this release introduces no new PR/AOQ or purchase-return monetary rules. APVs retain explicit-entry, VAT/WHT and payable validation. Existing collection, deposit and refund accounting definitions are preserved.

Shared goods inventory is delivered to the linked PO's physical `delivery_branch_id`. The server records the resolved destination in the nullable, read-only `receipt_note.inventory_branch_id`; returns use that recorded value, not subsequent PO edits. Missing/deleted/shared destinations fail before approval. Services and records without inventory items do not move stock. No historical destination is guessed for an already-received shared RN without a recorded destination: reconcile it separately before returning goods.

The master approval connection executes allowlisted schema-qualified inventory statements. Source and destination must share a database endpoint, use InnoDB, and grant the master connection appropriate cross-schema permissions. The whole operation rolls back with document approval on failure. Item row locks serialize stock/cost updates; `shared_procurement_inventory` records the master document/owner/destination and prevents repeated movement. Stock activity labels explicitly identify master-owned shared receipts/returns. Destination inventory tables with independent accounting hooks are rejected for review, preventing duplicate clinic GL postings.

The shared PO sequence is locked in `shared_procurement_number`, by branch code/year across master owners. Existing PO number format is retained. The first allocation initializes from existing master numbers; subsequent allocations are transactional. No existing numbers are changed.

## SQL and prerequisites

- `schema.sql`: missing master procurement tables, ownership/parent indexes, nullable RN inventory destination, sequence/movement registries, and additive approval reminder tables. Existing records remain in place; no branches or documents are seeded.
- `posting.sql`: shared-only procurement posting helpers/hooks; no posting or backfill calls.
- `install.sql`: master-only prerequisite/conflicting-hook guard, then additive schema and scoped posting definitions.
- `rollback.sql`: removes release-specific helpers/hooks; restore exact prior overwritten VAT helper definitions from the backup afterward. Additive schema and business rows remain intact.
- `build.py`: regenerates the scoped installer and embeds the same schema/posting into both complete master trigger sources. It performs no database execution.

Install the current shared-fund schema/posting dependencies first (`gl_shared_clear`, `gl_shared_je`, `gl_account_id`, `gl_post_apv`). The canonical `sql/shared_funds/posting.sql` now preserves the existing master refund context and accounting dispatch; its builder embeds canonical definitions rather than rebuilding older clinic approximations. Run `python3 sql/shared_funds/build.py` before `python3 sql/shared_procurement/build.py` when regenerating the complete SQL sources.

Master must already have the catalog/reference tables used by procurement (`branch`, `user`, `role`, `supplier`, `department`, `capital_expenditure`, `item`, `item_conversion`) and migrated/manual-aware GL/JE tables. Active account codes `104-030`, `106-000`, `201-110`, and `201-111` must exist without conflicting mappings. Review existing master procurement table definitions against `schema.sql` before installing; `CREATE TABLE IF NOT EXISTS` does not upgrade unrelated legacy column differences.

## Deployment order (requires a separate deployment request)

1. Inspect production/UAT independently: current source schemas, mappings, hooks, shared-fund dependencies, destination engines/permissions, and existing master data. Stop on unexpected existing procurement posting paths or missing prerequisites.
2. Pause affected procurement/APV writes and reminder workers; record prior settings and drain requests. Back up exact affected routine/trigger definitions, procurement/reminder/APV tables, GL/JE/items, and participating destination stock/cost tables to private environment-specific directories outside the web root.
3. Stage/checksum the scoped installer. Execute it against the selected master only, without `--force`:

   ```bash
   mysql -u root -p mytemr_master < install.sql
   # Or, in the separately selected UAT deployment:
   mysql -u root -p mytemr_master_uat < install.sql
   ```

4. Deploy the matching PHP changes, then compare installed definitions, ownership/index dependencies, and unchanged historical-row fingerprints. Verify existing branches and unrelated hooks remain unchanged. Restore the prior write/worker settings after verification.

The scoped release does not replace APV, collection, deposit, refund or audit hooks. Do not apply the complete GL trigger scripts as part of a scoped deployment. No application/database deployment, historical relocation, inventory backfill, or accounting repost has been performed by local implementation.

## Recovery

Keep writes paused on partial installation. Remove newly installed release hooks/helpers using `rollback.sql`, restore exact prior affected definitions and PHP files, and verify recovery before resuming writes. Do not automatically retry failed DDL. Preserve additive tables/columns and all business records; do not reverse inventory or accounting through ad hoc deletes.

## Local checks

Use only disposable local MariaDB schemas. `tests/shared_funds_check.py` initializes `shared_funds_fixture`; run existing `tests/shared_funds_models_check.php` and then reset the fixture before `tests/shared_procurement_check.php`. The procurement suite creates temporary delivery schemas and verifies master ownership/references, RN/APV accounting, delivery quantities/cost, return destination freezing, rollback, numbering contention, queries, and controller routing. `tests/approval_reminders_check.php` verifies the existing approval delivery lifecycle. Tests support the isolated `BILLING_FIXTURE_SOCKET`; no live email or database connections are required.
