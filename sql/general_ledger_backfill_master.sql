-- Backfill billing / collection / billing deposit / credit-debit memo
-- into mytemr_master.general_ledger.
-- Apply sql/general_ledger_triggers_master.sql on mytemr_master first, then:
CALL gl_backfill_billing();
