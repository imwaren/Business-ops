# Master billing AR posting and October 9 repair

## Behavior

Both master scripts post active positive billing entries as soon as a bill is created. Eligible headers are `pending`, `approved`, `open_bill`, and `closed_bill`; pending/open/closed child entries are supported. Each movement debits `102-113` and credits `102-113B`, preserving the billing creation-date expression. Branch 0 is retained for floating/unassigned entries. Negative branches and nonpositive amounts do not post.

Transaction numbers are resolved only from the entry's actual clinic connection (1, 2, 3, 4, 7). GL remarks, journal item remarks, and the journal header include the billing and entry IDs, plus the transaction number when available. No transaction number is fabricated for a missing link.

`gl_clear_billing_movement` is restricted to `billing_entry` and `billing_payment`. It retires previous GL, journal headers, and journal items before posting again. Cancellation, source soft deletion, and repeat calls leave no duplicate active set. Collection account selection, withholding, existing allocation rules, and payment-date rules are unchanged. Master deposit and diagnostic procedures are untouched.

## Scoped deployment

Use `install_uat.sql` or `install_prod.sql` in the matching master database, **not the complete general-ledger scripts or an unrestricted backfill**. These files replace only:

- `gl_billing_transaction_no` (new lookup function)
- `gl_clear_billing_movement` (new scoped cleanup procedure)
- `gl_post_billing_entry`
- `gl_post_billing_payment`

Existing billing and collection hooks remain installed unchanged. No API PHP file or database table is deployed.

`deploy.py` is the VPS6 root-only maintenance runner. Its frozen cohort is based on `added_on >= '2026-10-09' AND added_on < '2026-10-10'`, including deleted/cancelled sources for cleanup. It backs up scoped source/posting rows, routine definitions, and hook definitions, installs scoped SQL, repairs bills then payments in one transaction, and verifies before committing. Sample repeat posting runs inside the same transaction. No source dates, balances, statuses, or amounts are changed.

Normal database writes are briefly paused with `read_only`, and the original setting is restored. UAT's existing GL audit trigger has a nonprivileged definer. During repair only, its definer is temporarily root so its unchanged audit body can execute while writes are paused. Restoration uses binary-safe, comment-preserving SQL; original hook body, definer, and SQL mode must match before writes resume. Failure after commit keeps writes paused for recovery review.

The runner streams SHA-256 fingerprints of all billing/payment source rows, account types, out-of-scope master postings, the five diagnostic GL/JE/item tables, and protected definitions. It validates active GL/JE equality and source references. It never executes a full historical master backfill.

## Completed rollout — October 10, 2026 (Asia/Manila)

The final verified deployment and recovery package on VPS6 is `/root/billing_ar_20261010_0015_final/{uat,prod}`. Each directory contains `cohort.json`, scoped SQL row backups, original definitions and hook snapshots, restore SQL, settings snapshots, protected fingerprints, and `verified.json`.

- UAT: 59 bills, 65 active entries, debit/credit totals of PHP 66,800 each. This includes 36 branch-0 entries with billing/entry reference fallback.
- Billing 5754: entries 75975 and 75976 each debit/credit PHP 900; transaction numbers 10135016 and 10129014 appear in GL and journal references.
- Production: no bills or collections were created in the selected day, so no historical production postings were rebuilt.
- Neither environment had a collection in the selected creation-date window. The collection procedures were deployed and verified with isolated cash/check, multi-branch, unidentified, withholding, and deletion fixtures.

Original pre-change UAT definitions/row backups are also preserved in `/root/billing_ar_20261010_0015/uat`; the first attempt rolled back its accounting transaction. Original pre-change production definitions/row backups are in `/root/billing_ar_20261010_0015_retry1/prod`. Intermediate UAT repair records are retained in the sibling retry directory. The final package contains the completed postflight checks.

Repair attempts exposed UAT's read-only audit-definer restriction and exact-definition restoration differences caused by client comment stripping and CRLF normalization. Recovery preserved the original trigger exactly; final write settings and protected fingerprints were verified before resuming service.

## Verification

`tests/billing_ar_movement_check.py` uses networking-disabled disposable local databases only. It installs both environment variants twice and checks initial/approved/pending/open/closed posting, branch routing, branch-0/missing-link fallback, original dates, zero values, cancellation/deletion, rollback, collection math, active journal deduplication, and the audit-definer maintenance case. The latest run passed 90 checks. No live test accounting records were created.

Recovery SQL containing original audit bodies must be read as bytes and run using `mariadb --comments --binary-mode`; ordinary text-mode reading or default client comment removal changes the stored definition. Do not restore source tables casually: their fingerprints were unchanged. Any post-commit posting restore must be limited to the frozen source IDs and run under a protected maintenance window, retaining later unrelated activity.
