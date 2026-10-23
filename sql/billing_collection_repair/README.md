# Master collection posting repair

Missing payment 1447 is caused by the parent insert running before allocation
rows exist, with no allocation hooks to rebuild postings afterward. Its explicit
account 53 already overrides MOP mapping. The previous WHT denominator also used
the net receipt instead of the allocated AR total.

## Install and repost

Deployment is a separate action; the current remark upgrade is prepared locally.
Back up the master GL/JE/items and existing affected routine/trigger definitions
before installation. Use a brief collection-write maintenance window because
routine/trigger DDL is not transactional. Run against the chosen master only:

```bash
mysql -u root -p mytemr_master < install.sql
mysql -u root -p mytemr_master < repost.sql
```

For UAT use its master database instead. Run from this directory. Do not use
`--force`. `install.sql` does not backfill data. `repost.sql` explicitly selects
payment **1447** and rolls back its data changes if GL/JE validation fails.
It can be rerun: previous automated entries are soft-deleted, retaining history.
No migrated or source-less manual journals are rebuilt. Review any error before
retrying; if the temporary verification procedure remains after a failure, the
next run replaces it. Do not run repost.sql inside another transaction.

Existing master dependencies must be installed: `gl_account_id`,
`gl_billing_payer_label`, `gl_enrich_remark`, `gl_clear`,
and the existing GL audit/enrichment infrastructure. Both `general_ledger` and
`journal_entry` must have `is_migrated`; billing_payment must have `wht_account`.
No table migration is included.

## Behavior

- A valid explicit active parent account wins for every MOP, including bank and
  unknown values. Otherwise recognized cash/check, online_payment and configured
  others mappings apply. Trim/case normalization is supported. Unknown modes
  without a usable account raise a clear error; bank without an account also
  requires an explicit selection rather than guessing a bank.
- Parent payment INSERT/UPDATE and allocation INSERT/UPDATE/DELETE rebuild the
  active automated GL and JE. Moving an allocation rebuilds both payment IDs.
- Identified AR credits equal the positive active allocation total on live
  branch-linked billing entries. Collection debit equals AR minus WHT. WHT is
  allocated over that total using cumulative four-decimal rounding, ordered by
  branch ID, so it sums exactly to header WHT. A valid wht_account overrides
  fallback CWT code 105-000.
- During incremental allocation creation, WHT is capped to the current AR
  total; once all allocations exist, header WHT is used in full. Source amounts
  are never changed. Existing unidentified/deposit monetary rules are retained.
- Payment dates and branch attribution are retained. No API code changes.

Payment 1447, based on the inspected source: branch 4, debit account 53
**2332.4000**, debit CWT account 165 **47.6000**, credit AR Cebu account 57
**2380.0000**. Expected one approved automated JE and three active GL/item rows.

The same definitions are synchronized into master production/UAT trigger scripts
and both billing_ar_repair installers. Reinstalling those retains this fix.

## Local validation

`tests/billing_ar_movement_check.py` uses only disposable schemas on a local Unix
socket. Set BILLING_FIXTURE_SOCKET and BILLING_FIXTURE_USER for a dedicated
networking-disabled fixture server. It tests both environment installers and
the standalone installer, allocation hooks, payment 1447, MOP precedence and
errors, WHT rounding, moves/deletions, repeated posting, rollback, and retained
migrated/manual records. No live server is used by this test.

## Collection journal header remarks

The journal header for `source = 'billing_payment'` now appends nonblank
`billing_payment.remarks` after its existing payment/transaction/patient context,
separated by ` | `. For example:

```text
billing_payment #1448 | existing context | received by cashier
```

Null, empty, and whitespace-only notes add nothing. Outer spaces are trimmed;
internal newlines and quotes remain intact. Every posting builds the header from
current source data, so editing/reposting notes replaces the old suffix rather
than accumulating it. GL line remarks and journal-item remarks are unchanged.
The generic rebuild and incremental helper use the same formatter; payment
rebuilds exclude migrated GL/JE records, retaining manual journals as before.

For an already-installed collection fix, the narrowly scoped upgrade is:

```bash
mysql -u root -p mytemr_master < remarks_install.sql
```

Use `mytemr_master_uat` for UAT. Back up the four affected definitions first:
`gl_billing_payment_journal_remark`, `gl_post_billing_payment`, `gl_je_rebuild`,
and `gl_je_from_gl` (record an absent formatter as absent). Pause collection writes
while replacing them, capture errors, and restore exact prior definitions on
failure before resuming writes. The upgrade requires the existing
`gl_journal_base_remark`/`gl_enrich_remark` helpers and collection/accounting schema.
It changes no hooks, schema, source amounts, or historical journal rows. Do not
run `repost.sql` solely to install the remark change. Previously posted journals
receive current notes only when their payment is subsequently rebuilt.

Both complete master scripts, this full collection installer, and the two AR
repair installers retain the formatter. Header, allocation and generic-rebuild
regressions cover blank/multiline/quoted notes, note edits, repeat posting,
unchanged line accounting, and migrated/manual preservation. This remark upgrade
is prepared locally; remote installation and historical updates are separate.
