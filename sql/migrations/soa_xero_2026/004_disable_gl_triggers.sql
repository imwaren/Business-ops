-- Drop billing GL triggers so migrated inserts do not write GL / JE.
-- Re-apply sql/general_ledger_triggers_master_uat.sql after populate.

DROP TRIGGER IF EXISTS trg_gl_billing_ai;
DROP TRIGGER IF EXISTS trg_gl_billing_au;
DROP TRIGGER IF EXISTS trg_gl_billing_entry_ai;
DROP TRIGGER IF EXISTS trg_gl_billing_entry_au;
DROP TRIGGER IF EXISTS trg_gl_billing_payment_ai;
DROP TRIGGER IF EXISTS trg_gl_billing_payment_au;
DROP TRIGGER IF EXISTS trg_gl_billing_payment_deposit_ai;
DROP TRIGGER IF EXISTS trg_gl_billing_payment_deposit_au;
