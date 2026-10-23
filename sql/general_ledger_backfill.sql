-- Backfill clinic source documents into THIS branch's general_ledger
-- (CSR, inventory, RN/APV, expenses, JE, cash_deposit, non_cash_deposit).
-- Billing / collection / billing deposit / credit-debit memo are backfilled
-- on mytemr_master (sql/general_ledger_backfill_master.sql).
--
-- Prerequisites (once per clinic DB: mobile, oakridge, mactan, consolacion, manticwood):
--   1. Apply sql/general_ledger_triggers.sql
--   2. INSERT IGNORE INTO gl_settings (branch_id) VALUES (7);
--      1 = mobile, 2 = oakridge, 3 = mactan, 4 = consolacion, 7 = manticwood
--
-- Then run:
CALL gl_backfill_all();

