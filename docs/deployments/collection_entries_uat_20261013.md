# Collection-entry report deployment — UAT

Completed October 13, 2026 at 12:54 PM Asia/Manila on VPS5.

## Installed files

The shared library was installed before either consumer:

- `app/Libraries/Billing_collection_entries.php`
- `app/Controllers/Billings.php`
- `app/Controllers/Reports.php`

UAT previously had the new Reports methods but lacked the shared library.
No model files, database migrations, test files, or production files were deployed.
Existing ownership and modes were preserved (Reports: 0664; Billings/library: 0644).

## Verification

- 152 local isolated collection checks passed, including comparisons with the
  tracked pre-refactor Billings implementation.
- Local and remote PHP syntax checks and local diff checks passed.
- All three installed SHA-256 checksums match the local release.
- 16 read-only deployment checks passed. Existing payment 1443 returned three
  entries with the same structure in Reports and Billings.
- Ordinary lists omit entries; required dates, invalid-ID handling, branch
  selection, and an existing collection with no entries were verified through
  CLI probes using the deployed controllers and real read-only model queries.
- The supplied HTTP credentials returned 401. Authenticated HTTP behavior was
  therefore not verified; no replacement token was created or borrowed.
- No new application error lines appeared during verification.
- Configuration, related models, schedules, and the corresponding production
  files retained their original fingerprints.
- FPM timestamp validation is enabled with a two-second revalidation interval.
  Verification followed a three-second wait; no service restart was required.

No billing, payment, or accounting records were created or changed. No schema
changes or backfills were performed. Normal HTTP authentication logging may run
for the attempted GET.

## Checksums

- Library: `b5ebb3253f2d2f249e968904593152f2b24a6ef252cf24269e81f72aafc27967`
- Billings: `bbeed52fd766024e95a19888f65384d6caa490e7f9224ed207b2b2951b02584b`
- Reports: `b890510e3791cface296f8a8c81c151097b9bf40bfcc00c70ddca020dcc6f0ab`

## Backup and recovery

Private backups and verification records:

`/root/collection_entries_20261013_125010/uat`

Exact previous controllers are under `before/app/Controllers/`. Because the
previous Reports controller referenced the absent library, use
`rollback_working/app/Controllers/Reports.php` for a working rollback rather
than restoring that incomplete controller.

Restore the original Billings controller and the working Reports baseline
atomically, using ownership and modes recorded in `original.json`; lint and
verify their checksums. Remove the new library only after both dependent
controllers are restored. `RECOVERY.txt` records this order.
