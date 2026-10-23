# YAKAP monthly and annual billing

Company **3210** (PHILHEALTH - YAKAP). EMR branches: 1 Mobile, 2 Oakridge, 3 Mactan, 4 Consolacion, 7 Mantlewood.

## API

- `GET /billings/get_tranche1?branch_id=2&year=2026`: all 12 months, monthly unique FPE counts, amounts, billed links/snapshots, completion/eligibility flags, and total/billed/unbilled/completed-unbilled aggregates.
- `GET /billings/get_tranche2?branch_id=2&year=2026`: annual FPE cohort and consultation/laboratory/antibiotic/NCD beneficiary counts, score breakdown, performance factor, gross amount, and billed reference/snapshot.
- `POST /billings/create_yakap_billing`: standard authentication, `branch_id`, `year`, `tranche_number`, and `month` for tranche 1. Optional `due_date`, `invoice_no`, `invoice_date`. Omitted due date defaults to the creation date in Asia/Manila.

Example creation payload (plus normal authentication):

```json
{"branch_id":2,"year":2025,"tranche_number":1,"month":12,"due_date":"2026-01-31"}
```

For annual billing, use `tranche_number: 2` and omit `month`. Both endpoints use normal success/error envelopes. Creation returns `billing_id`, `billing_entry_id`, `grand_total`, and the calculation snapshot.

Generic transaction lists no longer supply YAKAP tranche candidates, and generic billing create rejects them. Normal invoice metadata updates and existing approval/collection flows remain available. Derived amounts and periods cannot be edited through ordinary update; cancel and recreate instead.

## Counting and calculation

Each active EMR patient is counted once in the selected year, in the month of their earliest active FPE in that year. Annual service counts are unique members of that cohort. Laboratory results need not have `is_sent`. Medicine indicators use therapeutic prescriptions linked to active ekonsulta assessments and active master items; they are not confirmation of actual dispensing.

Tranche 1 is FPE count × 680. Tranche 2 uses targets/weights: consultation 1/0.30, laboratory 0.50/0.30, antibiotic 0.15/0.10, NCD 0.20/0.30. Round actual score, capped computation, and weighted score to two decimals (half up); sum the weighted scores and multiply by FPE count × 1020. Billing amounts are gross; withholding remains with collection.

Monthly bills become available at next month's opening; annual bills at next year's opening. Source data remains visible for unfinished periods, but creation is rejected. Zero-value bills are rejected. No retained-beneficiary carryover or submission deadline extension is implemented.

## Persistence and safeguards

Apply `schema.sql` to the environment's master database. It adds nullable billing-entry year/month/snapshot fields and an InnoDB registry with a unique branch/tranche/year/month key. Annual month is 0. The registry row is locked before creation, and the header, entry, and registry update commit together. Cancelled/deleted bills allow replacement; their old entries and snapshots are retained.

Billed references use active billing entries and non-cancelled headers, including pending billings. Preview live amounts can change with EMR corrections; the saved billed amount/snapshot remains separate. Legacy aggregate tranche entries without explicit periods require review; the API does not infer or backfill periods.

No GL/JE trigger changes are required. Existing trigger hooks receive the new header and aggregate entry in the same master transaction.

## Verification and release

Run `php tests/yakap_periods_check.php` against the disposable local socket fixture only. The test never uses configured live database credentials. Tests cover screenshot figures, counting, caps, boundaries, migration repeatability, duplicate prevention, rollback, approval thresholds, cancellation/deletion, branch separation, and missing-source errors.

Deployment recovery/evidence directory on VPS5 and VPS6: `/root/yakap_periods_20261009_1340`, with separate `uat` and `prod` subdirectories. VPS6 contains billing backups, before/after historical fingerprints, and schema validation. VPS5 contains original file backups, deployed checksums, and read-only preview results. On application verification failure, restore the saved PHP files; additive schema may remain. No historical billing or accounting rows are backfilled or rebuilt.
