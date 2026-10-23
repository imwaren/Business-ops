# Cash advances: APV accounting only

Cash-advance source insert/update hooks are removed. Both legacy posting procedures remain harmless no-ops. The superseding `funds_apv_only` release also disables direct liquidation and the other fund sources. This older installer now carries the same no-op liquidation procedure and filtered backfill routines, so reinstalling it cannot restore their direct accounting. Existing GL/JE/items are not modified. APV approval/payment rules are unchanged.

## Scoped installation (separate deployment authorization required)

1. Select and verify the intended database and current routine definitions. Back up exact `trg_gl_cash_advance_ai`, `trg_gl_cash_advance_au`, and the routines replaced by the selected installer, plus accounting tables. Record unrelated hooks for comparison.
2. Pause affected cash-advance/liquidation writes and backfill jobs and drain requests, retaining their prior settings.
3. For a clinic database use `install_clinic.sql`; for master/shared accounting use `install_master.sql`. These scripts use current canonical routine definitions: compare against installed definitions first and stop if there are unreviewed local customizations. Do not apply a complete GL script for this scoped change.

   ```bash
   mysql -u root -p SELECTED_CLINIC_DATABASE < install_clinic.sql
   # Master (select production or UAT explicitly):
   mysql -u root -p SELECTED_MASTER_DATABASE < install_master.sql
   ```

4. Execute without `--force`. Verify both cash-advance accounting hooks are absent, compatibility procedures contain only `DO 0`, liquidation routines have no cash-advance dispatch, and accounting/source row fingerprints remain unchanged. Confirm unrelated APV hooks and definitions are unchanged. Restore prior write/job settings.

DDL is not transactional. On failure, retain the pause and restore exact backed-up affected definitions; do not automatically retry. Rollback requires those exact backups because restoring former definitions re-enables direct posting. No automatic data cleanup or repair is included.

## Maintenance and local validation

Edit clinic production/UAT sources and canonical `sql/shared_funds/posting.sql`. Run `python3 sql/shared_funds/build.py`, `python3 sql/shared_procurement/build.py`, then `python3 sql/cash_advance_apv_only/build.py` to synchronize generated releases. No build command executes database SQL.

`python3 tests/cash_advance_apv_only_check.py` uses a disposable local socket/schema and checks compatibility calls, history preservation, scoped installation, absent source hooks, old backfill dispatch, balanced cash-advance APVs and repeat-post safety. Run `tests/apv_accounting_check.php` with the fixture socket/user for the broader APV regressions. No remote installation is performed by these checks.
