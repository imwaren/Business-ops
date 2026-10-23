# Depreciation items and branch-local assets

When creating or updating a depreciation entry, each `items[]` row needs an `asset_id` on `depreciation_entry_item`. If the client does not send `asset_id` (typical when the line comes from a receipt note), the API creates or reuses an `asset` on **that clinic database only**.

## SQL

Run [`sql/asset_item_id.sql`](../sql/asset_item_id.sql) on each clinic DB (mobile, oakridge, mactan, consolacion, mantlewood) and on master for column parity:

```sql
ALTER TABLE `asset`
  ADD COLUMN `item_id` int(11) DEFAULT NULL AFTER `cost_center_id`;
```

## Item payload rules

| `asset_id` | Required fields | Result |
| --- | --- | --- |
| Sent | Must exist on this branch | Use that id |
| Omitted (new line) | `name` and `item_id` | Reuse or insert, then store the id |
| Omitted (existing line with `items[].id`) | None extra if the line already has `asset_id` | Keep the stored id |

`item_id` is the catalog item from the receipt note line.

Example without `asset_id`:

```json
{
  "name": "Sysmex HISCL 800",
  "item_id": 123,
  "amount": 10000,
  "useful_life_months": 60,
  "start_date": "2026-06-01",
  "debit_account_type_id": 1,
  "credit_account_type_id": 2
}
```

## Reuse vs insert

On the **current** clinic DB only:

1. If `asset_id` is sent, use it.
2. Else find `asset` where `item_id` matches and `is_deleted = 0`. Reuse that id.
3. Else insert one row: `name`, `item_id`, `cost_center_id` (item or parent), `cost` from `amount`, `useful_life_years` from `useful_life_months / 12`, `as_of` from `start_date`.

The new row is **not** copied to master or other branches. Asset catalogs can differ per clinic.

`/assets` create, update, and delete write only the `cost_center_id` clinic DB. There is no master/branch row-count check, increment-ID sync, or `Entity_consistency` on assets.

## Endpoints

- `POST /depreciation_entries/create`
- `POST /depreciation_entries/update`

See [`docs/postman/Depreciation_entries.postman_collection.json`](postman/Depreciation_entries.postman_collection.json).
