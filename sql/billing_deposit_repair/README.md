# Billing deposit: use the actual net receipt

`billing_payment_deposit.amount` is already the net amount deposited. The old
branch formula multiplied it by gross AR allocations and divided by the net
collection header, turning 813.40 back into 830.00. The new procedure divides by
the total eligible allocation amount, so branch shares sum to the stored deposit
amount. EWT is not deducted again or posted again on deposit.

## Expected example

Deposit **1**, linked to collection **1448**, amount **813.4000**, branch **4**:

- Debit deposit bank account **53**: **813.4000**.
- Credit cash/check on hand, code **100-004** (current ID **13**): **813.4000**.
- One approved automated JE, two active GL rows and matching journal items.

Collection 1448 remains cash/check debit **813.4000**, CWT debit **16.6000**,
and AR credit **830.0000**. The poster does not change source amounts or the
collection's GL/JE. Each partial deposit posts only its own stored amount.

## Installation and selected repost

Prepared and tested locally only; not deployed to production or UAT.

Back up the current `gl_post_billing_payment_deposit` definition, deposit-source
GL/JE/items, and source rows first. Use a brief collection/deposit write hold
while replacing the routine because SQL DDL is not transactional. From this
directory, against the selected master database only:

```bash
mysql -u root -p mytemr_master < install.sql
mysql -u root -p mytemr_master < repost.sql
```

Use the UAT master database name for UAT. Do not use `--force`. `install.sql`
replaces only the deposit procedure; existing deposit INSERT/UPDATE hooks remain.
It does not backfill data or change application code or table schemas. The
production and UAT master trigger scripts contain the identical updated poster.

Dependencies: existing `gl_account_id`, `gl_enrich_remark`, GL enrichment/audit
infrastructure, and `is_migrated` on GL and JE. Deposit source, allocation, and
journal tables must support transactions (InnoDB).

`repost.sql` targets **deposit ID 1 only**. It checks debit/credit totals against
the stored amount, one automated JE, and matching item counts/totals before
commit. It rolls back on error. Run outside any existing transaction. Repeating
it soft-deletes previous automated posting sets and rebuilds one active set;
migrated and source-less manual journals are preserved. Review errors before
retrying; a subsequent run replaces the temporary verification procedure.

Before committing a remote repair, verify that ID 1 still belongs to the
intended payment and amount. No remote changes are part of this implementation.

## Allocation and edge cases

- Active, positive branch allocations on live billing entries determine weights;
  deleted allocations, deleted entries, and missing/nonpositive branches do not.
- Four-decimal cumulative rounding ordered by branch ID preserves the exact
  deposit total and balanced pairs, including small amounts and multiple branches.
- A positive live deposit without eligible allocations or valid bank/cash
  accounts raises an explicit error before retiring prior postings.
- Zero amounts, deleted deposits, and deposits whose parent collection is deleted
  retire only the selected deposit's automated postings when its poster runs.
- Deposit dates, actor attribution, branch attribution, and enriched remarks keep
  their existing semantics. The separate `check_deposit` module is unchanged.

## Local validation

`tests/billing_deposit_check.py` uses disposable local schemas and exercises real
INSERT/UPDATE hooks, the scoped installer/repost, net amounts, partial deposits,
rounding, missing allocations, deletion, repeat calls, and protected journals.
Set `BILLING_FIXTURE_SOCKET` and `BILLING_FIXTURE_USER` to a dedicated,
networking-disabled local MariaDB fixture instance.
