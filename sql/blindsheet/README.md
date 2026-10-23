# Items blindsheet workflow

UAT and production deployments are verified. The scoped release contains additive schema and future-posting support only; no historical backfill is needed. See `docs/deployments/blindsheet_20261013.md` for environment checksums, verification results, and recovery locations.

## Authentication

Ordinary callers retain the existing master API-key, requester-token, and permission checks.

For **only** `items/get`, `items/get_blindsheet`, `items/blindsheet`, and `items/blindsheet_change_status`, CSR callers supply:

- `origin=csr` in the request parameters, not the HTTP Origin header.
- The normal configured key in the `API-KEY` header, validated by `api_key_is_valid()` using existing `API_KEY`/`API_KEYS` settings. No separate CSR key is used. When older deployed constants omit `myt_env()`, the controller validates the same configured `API_KEY`/`API_KEYS` directly, preserving key normalization and precedence without changing shared files.
- `branch_id`, `requester`, and `token`. The user must be non-deleted and the token must match and be unexpired in that branch's local `user` table. Oakridge (`2`) therefore authenticates through its diagnostic database, not master.

CSR authentication permits all four endpoints, including approval/disapproval. Invalid CSR credentials never fall back to master authentication. The key alone grants no access. CSR callers bypass master page/permission records because local role IDs are a different namespace.

Supported inventory branches are `1` Mobile, `2` Oakridge, `3` Mactan, `4` Consolacion, `5` Warehouse, `6` Pharmacy, and `7` Mantlewood. Master (`0`) is a mirror and an audit-user source, not a selectable blindsheet inventory branch. Missing local user tables fail authentication.

## Controller/model layout

`get_blindsheet()`, `blindsheet()`, and `blindsheet_change_status()` are declared directly in `app/Controllers/Items.php` beside the adjustment workflow. Submission and approval use boolean controller `_attempt_*` helpers with `$this->error_message`. The endpoints own the transaction and final `$response_status`/`$response` handling, following adjustment. Approval separates inventory updates from status updates. The public endpoints use named payload variables and explicit validation/pending/master-record `if/elseif` branches, with one final response. Boolean helpers contain preparation, query, and transaction exception handling; operation locks are released before response creation on success or failure. CSR authentication and cascade handling also live in that controller; no blindsheet trait or library is required.

`Branch_item_blindsheet` retains list/count SQL, audit-name batching, and shared database-source configuration. Endpoint URLs, payloads, response fields, and prepared SQL are unchanged by this refactor.

## Requests and responses

`GET /items/get_blindsheet` requires `branch_id`. Optional filters: `blindsheet_id`, `item_id`, `branch_item_id`, `status` (`pending`, `approved`, `disapproved`), `page`, `data_size`. Successful responses use `{ "size": N, "data": [...] }`; no matches follow the existing adjustment HTTP 404 convention.

Rows contain the supplied table's fields, four nullable `*_by_branch_id` fields, `item_name`, `department_name`, and `added_by_name`, `updated_by_name`, `approved_by_name`, `disapproved_by_name`. Audit names are batched by their recorded source database after pagination. Null source metadata, missing users/tables, and deleted users produce null names without guessing that an ID belongs to master.

`POST /items/blindsheet` accepts normal JSON/form parameters. Example body (credentials are placeholders):

```json
{
  "origin": "csr",
  "branch_id": "2",
  "requester": "67",
  "token": "{{local_user_token}}",
  "branch_item_id": "123",
  "item_id": "456",
  "department_id": "1",
  "counted_by_id": "67,68",
  "physical_count": "12.50",
  "prev_qty": "10.00",
  "difference": "2.50",
  "date_counted": "2026-10-13",
  "remarks": "Physical stock count"
}
```

Required counting fields: `branch_item_id`, `item_id`, `physical_count`, `prev_qty`, `date_counted`, and `branch_id`. Quantities must be non-negative and fit `DECIMAL(13,2)` with at most two decimal places. `difference` is optional, signed, and server-derived; a supplied value must agree. A zero difference is valid. `department_id`, `counted_by_id`, and `remarks` are optional. This table has no `adjustment_type` or `date_delivered` input.

Submission checks the active item/branch-item relationship, mirrored stock/unit consistency, current stock against `prev_qty`, and an existing pending blindsheet. It creates a pending count with the same ID in every inventory schema, without changing stock. Response:

```json
{
  "blindsheet_id": 123,
  "item_id": 456,
  "message": "Branch item blindsheet added for approval successfully."
}
```

`POST /items/blindsheet_change_status` requires the same authentication and `branch_id`, `blindsheet_id`, `status`. Only `pending` → `approved` or `disapproved` is supported:

```json
{
  "origin": "csr",
  "branch_id": "2",
  "requester": "67",
  "token": "{{local_user_token}}",
  "blindsheet_id": "123",
  "status": "approved"
}
```

Approval rechecks stock while holding the exclusive blindsheet-operation lock. If stock changed, the count remains pending: disapprove it and submit a fresh count. Disapproval never changes inventory. Response contains `blindsheet_id` and a status-change message.

## Inventory and accounting

Blindsheet and inventory writes use adjustment’s existing `mirror_insert()` and `mirror_update()` helpers across their separate database connections. Submission and approval hold a per-environment named lock until the operation finishes; this serializes blindsheet operations, including ID allocation. The controller begins, commits, or rolls back its own transaction and reports success only after a successful commit. That rollback does **not** undo writes already committed through other mirror connections. A late insert, inventory, cost, status, or commit failure can leave partial mirror writes and source-branch accounting records; inspect and reconcile those mirrors before retrying. The lock does not serialize unrelated stock writers. Existing adjustment workflows are unchanged.

Approval sets stock to the physical count, records `Blindsheet` activity with its own source ID, and uses `inventory_adjustment` for ordinary count accounting. Nonzero changes use the existing purchase-unit conversion and `Item_cost::compute_next()` at current weighted cost, without changing catalog cost. Mirrored inventory quantity and unit must agree before submission or approval.

Inventory-history triggers retain two-decimal quantity differences. The diagnostic poster resolves `branch_item_blindsheet` through activity, branch-item, item, and branch relationships, independently of `branch_item_adjustment` IDs. Count increases debit inventory and credit the existing item expense/fallback account; decreases reverse that movement. Existing adjustment mappings and dates remain unchanged.

Mirrored history is retained, but **Blindsheet GL/JE posts only in the source branch**, using the existing journal-generation infrastructure. Missing/deleted blindsheet links produce no financial posting. Zero-difference approval records audit/status and latest activity but creates no inventory-history, cost-trail, GL, or JE lines. Item-deletion cascades also retire matching blindsheet rows; no history is automatically cleaned up during installation.

## Prepared SQL and prerequisites

Generate the package locally:

```sh
python3 sql/blindsheet/build_release.py /private/tmp/blindsheet_release
```

This only writes scoped SQL and a checksum manifest; it connects to no database.

For deployment, back up exact deployed PHP and affected trigger/routine definitions and pause affected writes. Verify all eight inventory connections point to distinct schemas on the same MySQL server. Then, in order:

1. `schema.sql`: apply in master plus the seven inventory schemas. It creates the supplied table where absent and adds nullable audit-source columns and lookup indexes idempotently. Existing audit-source values remain null; no account or history backfill is performed.
2. `inventory_history.sql`: compare each live `trg_branch_item_update` definition with the included baseline. Stop on unexplained differences; preserve unrelated custom logic. The intended change is only `qty_diff` from `INT` to `DECIMAL(13,2)`. Install in all eight inventory schemas.
3. `accounting_uat.sql` / `accounting_prod.sql`: install the matching scoped file in diagnostic schemas with existing GL/JE support. It replaces only `gl_item_history_remarks`, `gl_post_item_history`, `trg_gl_item_history_ai`, and `trg_gl_item_history_au`. Existing remark helpers, account lookup, journal routines, audit triggers, and master accounting posters remain installed and unchanged. No posting procedure is invoked by these installers.
4. Deploy the feature's PHP files using the existing ordinary API-key configuration. Deploy UAT and verify before production when rollout is authorized.

Each configured mirror connection needs SELECT on its dependency tables, INSERT on its blindsheets/item-cost trails, and UPDATE on its blindsheets/branch inventory. The master readiness/lock connection needs visibility into the required schema, trigger, and routine definitions; trigger definers retain their existing permissions. All affected tables and installed trigger-written accounting tables must use InnoDB. The five clinic schemas must have GL/journal tables and the new hooks; Warehouse and Pharmacy retain inventory-only behavior; their ledger tables do not require diagnostic posting routines.

Runtime preflight rejects missing tables, audit columns, required privileges, fractional inventory triggers, or blindsheet accounting dependencies before beginning writes. When grant metadata does not describe effective access, empty SELECT and EXPLAIN INSERT/UPDATE statements validate that access without executing writes. It changes no grants and performs no automatic schema repair.

## Verification

`tests/items_blindsheet_check.php` uses only a disposable networking-disabled local MariaDB socket under `/private/tmp/items_blindsheet_fixture`; it never loads a configured live connection. It installs both diagnostic SQL variants and repeatable migrations in synthetic schemas, executes real controller `_attempt_*` helpers and list/model/endpoint code, and verifies authentication, namespace isolation, zero and fractional counts, stock/cost/GL/JE effects, stale counts, boolean helper contracts, partial writes after late mirror/outer commit failures, and concurrent submissions/approvals. It creates no real users, inventory, or accounting records and sends no emails. The final suite includes 296 checks; the Postman validator checks seven requests and 20 illustrative saved responses.
