# UAT memo journal search references

Completed October 14, 2026. UAT master only; no PHP or production deployment.

## Diagnosis

Credit memo 4 was already posted: GL 154798/154799, approved journal 76061, and items 232152/232153 were balanced at PHP 600. The journal GET searches header remarks. The header contained `credit_memo #4`, omitting the memo control and invoice numbers, so both screenshot searches returned no results.

## Changes

- Added `gl_journal_base_remark()` to the shared master fragment and both local master SQL variants.
- Updated `gl_je_from_gl()` and `gl_je_rebuild()` to use it before existing transaction/patient enrichment. Financial logic is unchanged.
- Installed only those three objects in `mytemr_master_uat`, preserving existing definers, SQL modes, character sets, collations, and unrelated definitions.
- Appended `Credit memo #CM2120261014000002`, `Billing #2`, and `Invoice #C-PK0008` to journal 76061's original remarks. The correction records a null system updater and correction time.
- The other five existing memo headers remain unchanged. New or ordinarily regenerated credit/debit memo journals include their available references.

## Verification

68 isolated checks passed across both SQL variants. They cover actual approval/posting hooks, control/invoice search, financial logic equivalence, context preservation, Unicode and missing metadata, repeat formatting, rollback, SQL-client exit recovery, repeated rebuilding, and disapproval.

Live checks verified exact installed routine bodies/settings and unchanged protected definitions. All GL and journal-item rows, every other journal header, target financial/audit-source fields, credit/debit memos, bills, and billing entries have unchanged fingerprints before and after the correction. No live posting or rebuild procedure was called.

Both October searches now return journal 76061 with matching row/count results. The original source IDs, approved status, financial date, accounts and PHP 600 totals are preserved. Normal maintenance settings were restored (`read_only=0`, event scheduler OFF, cron active).

Authenticated HTTP verification was unavailable: the previously supplied requester 67 token did not match a valid unexpired UAT login. Verification therefore used the journal search SQL predicates rather than claiming an HTTP success.

## Backups and recovery

VPS6 root: `/root/memo_journal_refs_20261014_1040`.

- `uat_retry/`: successful rollout's exact routine definitions/restoration SQL, target header/GL/item dumps, before/in-transaction/after fingerprints, routine hashes, verification result, and maintenance settings.
- `uat/`: first attempt's backups, private error log, and verified recovery evidence.
- `package/`: scoped SQL manifests and deployment helper.

The first attempt's preservation-check query encountered a MariaDB collation mismatch before commit. The correction rolled back, exact prior definitions and original header were restored, fingerprints matched, and maintenance was restored. The validator was changed to a binary comparison, and recovery now reconnects after a SQL-client error. The successful retry passed all checks.

Files are private; backups may contain sensitive accounting context and must not be committed. Restore exact saved routines and the three permitted header fields only after verifying the current source record and preserving any later business activity. Keep the maintenance hold if recovery validation fails.

Helper checksum (local and VPS6 match):
`f5d684103f62ddd9d42c81cecb54f0c417197f1459636be581fc0fa16095fa97`.
