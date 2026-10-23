# Item and patient names in generated accounting remarks

## Scope

The four diagnostic/master SQL templates enrich generated GL remarks, journal
items, and journal headers. Installation performs no historical update, backfill,
or accounting rebuild.
Deployed to UAT and production on October 12, 2026 using the scoped package in
`sql/gl_remark_names/`; see that package's README for rollout evidence and recovery.
No historical data was changed during deployment.
Existing postings keep their remarks until their ordinary posting procedure
reposts them.

Accounts, amounts, dates, eligibility, legacy APV compatibility, and journal
grouping remain governed by the existing posting procedures. A GL `BEFORE INSERT`
hook enriches only `NEW.remark`. Manually entered journal movements remain intact.
The covered master journal rebuild path soft-retires its prior journal header/items
before generating one active set; deposit and memo reposts previously appended
items to the active header. Retirement happens only during ordinary reposting,
not script installation, and preserves the earlier accounting rows as history.

## Metadata sources and formatting

- Diagnostic transaction, payment, discount, sales-return, refund, and linked
  non-cash-deposit movements resolve the local transaction by its actual ID.
- Patients come from `transaction.patient_id` in that transaction's branch.
  Encrypted name components are decrypted using the configured application key
  and formatted as `Last name, First name Middle name Suffix`. Empty components
  are skipped. A patient label requires a nonblank transaction number. Failed
  decryption has no ciphertext/plaintext fallback.
- Transaction medicines resolve `booking_medicine.medicine_id` through the master
  item catalog. Item-history lines use their own `item_id`, not unrelated medicine
  names from the transaction. Overlapping activity IDs pointing to different
  transactions do not produce a guessed patient.
- Receipt-note lines use their item catalog links. Receipt-note APVs and APV
  payments include the distinct active receipt-note item names. Supplied APV-entry
  remarks remain part of each generated remark.
- Master billing entries resolve their own branch and transaction. Collections,
  linked deposits, and credit/debit memos use their linked billing entries.
  Branch-specific GL lines include only that branch's references; combined journal
  headers retain distinct branch/transaction pairs in branch/ID order.
- Missing metadata leaves financial postings and existing references intact.
  Helpers deduplicate generated context and preserve document/item IDs and
  transaction numbers. Name aggregation uses cursors rather than `GROUP_CONCAT`.

Example with synthetic data:

```text
Billing #100 / entry #12 | branch #2 / transaction #EXAMPLE-001 / Patient: Sample, Alex | Items: Sample medicine
```

Canonical helper definitions are in `sql/fragments/`. Each full SQL template embeds
those definitions between the shared-helper markers and substitutes the correct
master/diagnostic schema names. Keep the fragments and their embedded copies in
sync when editing them.

## Preparing SQL for a future authorized installation

Raw templates contain `__GL_ENCRYPTION_KEY_HEX__`; their guard rejects installation
until the key is rendered. The preparation command reads the selected environment's
configuration using the project's CodeIgniter dotenv parser, writes private files
outside the checkout, and executes no SQL.

```sh
python3 scripts/prepare_gl_remarks_sql.py \
  --environment uat \
  --env-file /absolute/path/to/uat/.env \
  --output-dir /private/tmp/prepared-gl-uat

python3 scripts/prepare_gl_remarks_sql.py \
  --environment prod \
  --env-file /absolute/path/to/production/.env \
  --output-dir /private/tmp/prepared-gl-prod
```

Use `--php /absolute/path/to/php` if needed. The key comes from `ENCRYPTION_KEY`,
with `encryption.key` as a fallback; unrelated shell key variables are excluded.
The output directory has mode `0700`; rendered SQL files have mode `0600`.
For the deployed legacy production configuration, the scoped stage helper reads
the runtime constant from the application configuration. The dotenv-only preparation
command above requires an active key entry in its selected environment file.
Templates set the installation connection to `utf8mb4_unicode_ci` so stored
routine literals and helper results use compatible collations.
Rendered files and installed function definitions contain key material. Keep them
outside version control and avoid displaying their contents or database routine
definitions in logs. The functions use a rendered literal, not session variables.

Before any later installation, use the correct environment's prepared diagnostic
script for its branch schemas and the prepared master script for its master schema.
The existing schema prerequisites and branch `gl_settings` still apply. **Do not
run a historical backfill as part of remark enrichment.**

`sql/migrations/journal_entry_remark_names.sql` widens both journal remark columns
to nullable `LONGTEXT`. The same repeatable ALTERs are embedded in all four scripts;
they do not change existing remark values. GL remarks already use `LONGTEXT` in
the supplied schema.

## Local verification

`tests/general_ledger_remark_names_check.py` installs all four complete prepared
scripts twice into disposable local schemas using synthetic encrypted patients.
It compares active monetary vectors with the original procedures and exercises
receipt-note/APV/payment, CSR/discount/refund/cancellation, deposit, billing,
collection, and memo remark paths. It checks same-ID transactions across branches,
missing/blank metadata, Unicode components, ambiguous item-history links, long
details with a small `group_concat_max_len`, duplicate prevention, and renderer
privacy/environment isolation. No real patient names or keys are printed.

Existing focused billing and APV monetary tests use an identity metadata formatter
in their minimal schemas; the full installation test covers the real lookups.
Run the checks only against the disposable local test socket, not configured UAT
or production databases.

Local verification on October 12, 2026 passed 126 remark/integration checks,
90 billing regression checks, 101 APV regression checks, and 64 scoped-deployment
recovery checks. All four full and scoped scripts installed twice; installation
preserved every existing accounting row, and ordinary
reposting preserved GL/journal financial vectors with one active journal per
covered master movement. PHP/Python syntax and `git diff --check` passed.
