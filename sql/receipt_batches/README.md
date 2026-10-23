# Receipt batches and transfer report

## Local changes and SQL prerequisites

Alphanumeric batch support is deployed to UAT and production as of October 16, 2026. See the [schema-only deployment record](../../docs/deployments/batch_codes_20261016.md). The earlier integer-based receipt/transfer release was deployed on October 15, 2026; its [deployment record](../../docs/deployments/receipt_batches_20261015.md) remains historical. See the updated [Postman collection](../../docs/postman/Receipt_notes_transfers.postman_collection.json) for the current API contract.

For a separately authorized rollout, back up definitions and affected rows, then run `install.sql` explicitly in each configured master and inventory schema. The October 16 rollout preserved the already-current deployed PHP files; it used `scripts/deploy_batch_codes.py` rather than the older nine-file PHP release tooling. Do not run it on UAT/production as part of local development.

All three batch columns use nullable `VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin`. The parent receipt item contains a CSV; each expiry/transfer row contains one code. The installer:

- Checks all existing batch column definitions before changing columns; incompatible types, generated columns, and non-nullable definitions stop installation.
- Adds missing columns/indexes only where their tables exist.
- Converts nullable expiry/transfer `INT` columns to text. Existing `12`, `0`, and null values become `"12"`, `"0"`, and null; it cannot recover zeros that integer storage previously removed.
- Preserves compatible wider `VARCHAR` lengths, comments, defaults, and existing indexes; standardizes the case-sensitive charset/collation without business-row UPDATE statements.
- Is repeatable. It does not silently shorten columns or change stock, quantities, accounting, or historical batch assignments.

DDL is not transactional. Retain compatible schema changes if a later operation fails; do not narrow text columns back to integers after text codes have been saved. Index/row-size limits must be reviewed before live installation; an incompatible DDL operation fails rather than truncating values.

## Batch identifier rules

Codes are printable UTF-8 text up to 255 characters, including letters, digits, punctuation, and internal spaces. Surrounding ordinary spaces are trimmed. Commas are reserved for the parent CSV, and control characters/invalid UTF-8 are rejected. Null/blank values mean no batch. Non-negative integer inputs remain accepted for compatibility and are stored/returned as strings.

Case and leading zeros are significant: `AB123`, `ab123`, `0012`, and `12` are four different batches. `"0"` is a valid code. Parent CSV values are deduplicated in submitted order and compared as exact string sets against non-null expiry codes. The normalized parent CSV must itself fit 255 characters. Distinct lookup values use lexicographic, case-sensitive ordering, not numeric ordering.

## Receipt payloads

```json
{
  "items": [{
    "item_id": "{{item_id}}",
    "qty": "10",
    "unit": "{{unit_id}}",
    "batch_no": "AB123,ab123,0012,12",
    "expiry_dates": [
      {"date": "2028-01-01", "qty": "3", "batch_no": "AB123"},
      {"date": "2028-02-01", "qty": "2", "batch_no": "ab123"},
      {"date": "2028-03-01", "qty": "2", "batch_no": "0012"},
      {"date": "2028-03-01", "qty": "3", "batch_no": "12"}
    ]
  }]
}
```

Repeated batch codes across expiry dates are allowed. Expiry quantities must be non-negative and may total less than, but never more than, the parent quantity. Omitted batch keys on retained update rows keep saved values; explicit null clears them only if parent/child sets still agree. Existing replacement/soft-delete and APV behavior is unchanged. GET returns the parent CSV and individual string codes.

## Transfers

Use optional string `items[n][batch_no]`; different batches of one item use separate lines. Remaining batch stock equals approved active expiry quantities plus actual incoming transfers, minus actual outgoing transfers and outstanding pending/approved/opened reservations. Existing unit conversions, ordered locking, total-stock checks, weighted costs, and accounting behavior are preserved. PHP grouping keys and SQL bindings preserve exact codes, including purely numeric strings.

Receive with parallel `transfer_item_ids[]` and `received_qtys[]`; legacy `item_ids[]` works only for unambiguous lines. Quantities remain cumulative, and repeated completed receipts, decreases, over-receipt, or disapproval after movement are rejected. Partial receipts retain the unreceived reservation.

Existing mirroring uses separate connections. A late mirror/cost/status/commit failure can leave earlier writes committed. Inspect and reconcile the transfer, stock, history, and cost trails before retrying; no automatic repair/replay is included. Sales, consumption, and adjustments are not batch-attributed, so total-stock validation remains an additional safeguard.

## Read endpoints

- `GET /items/get_item_filters`: four independent distinct lists under `data.brand`, `generic`, `dosage`, and `form`, from non-deleted master items.
- `GET /items/get_item_batches`: requires `item_id`, `branch_id_from` (1–7), and `date` (`YYYY-MM-DD`). Reads that source branch's approved active receipts and returns distinct string codes where expiry `date <=` the supplied date. It is not a stock-availability or unexpired-batch guarantee.
- `GET /items/transfer_report`: optional `item_id`, exact string `batch_no`, brand/generic/dosage/form, comma-separated `kind` OR tokens, item-name search `term`, and pagination. The local extension returns approved receipt batch allocations alongside actual received transfer lines, including partial movements. Read each receipt from its configured source branch and transfers once from master. Unlabelled beginning stock and no-movement placeholders remain excluded; unrelated units are not summed.

Batch/report responses retain `{size,data}` and existing no-results/errors; filter lists return empty arrays successfully. Normal authentication/permissions remain unchanged. No new CSR access is added.

### Local receipt starting rows

The receipt-row report extension is **local only**, with no additional SQL or UAT/production deployment. Existing alphanumeric batch support remains deployed as documented above.

Each active expiry allocation with a nonblank batch code under an approved active receipt produces an `activity: "Receipt"` row. Its `quantity` and `beginning_qty` are the original expiry-row quantity, including zero. Allocations are not grouped or reduced after transfers. Receipt rows include the source branch, item metadata, source unit/conversion, receipt/item/expiry IDs and `expiry_date`; transfer-specific fields and `current_qty` are null. `movement_date` uses receipt `receive_date`, falling back to its creation date rather than the expiry date. Transfer rows retain cumulative received quantities and have null `expiry_date`.

The combined report applies identical filters/counts before pagination and sorts by movement date, receipts before transfers on matching dates, then deterministic item/branch/source IDs. A batch without transfers still returns its receipt rows. Case, leading zeros and `"0"` remain significant. Missing unit metadata does not remove an otherwise eligible receipt allocation. This report does not calculate a remaining batch balance.

Optional `term` searches only `item.name` with a bound `%term%` SQL `LIKE` condition across both sources, combined with other filters using AND. Surrounding whitespace is trimmed; omitted/null/blank values skip the filter while `"0"` remains searchable. Non-string, non-null values return HTTP 400 with `term must be a string or null.` Existing database collation and SQL wildcard behavior are preserved. This search addition also remains local only.

## Isolated verification

Tests cover normalization, case/zero separation, quantity reservations, incoming movements, partial receipts, filters, and migration preservation/repeatability. Database tests override every connection with guarded `receipt_batch_test_*` schemas on an explicitly enabled, socket-only disposable MariaDB server with networking disabled; no application databases or real records are used.

```sh
php vendor/bin/phpunit --no-coverage --filter 'ReceiptBatchTest|TransferBatchControllerTest|ItemLookupControllerTest|BatchIdentifierTest' tests/unit
RECEIPT_BATCH_TEST_SOCKET=/private/tmp/disposable_fixture/mysql.sock php vendor/bin/phpunit --no-coverage tests/database/TransferBatchTest.php
```
