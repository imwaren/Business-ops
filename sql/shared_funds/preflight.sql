-- Read-only review. Save results with the definition backup before installing.
SELECT DATABASE() AS target;
SELECT id,name,type,is_deleted FROM branch WHERE type='shared' ORDER BY id;
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('accounts_payable_voucher','direct_payment','journal_entry','journal_entry_item','general_ledger') ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT
FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
AND EVENT_OBJECT_TABLE IN ('petty_cash','cash_advance','liquidation','liquidation_detail','reimbursement','reimbursement_item','replenishment','replenishment_item','change_fund','direct_payment','accounts_payable_voucher','accounts_payable_voucher_entry','accounts_payable_voucher_payment_detail','journal_entry','journal_entry_item','general_ledger');
SELECT ROUTINE_NAME,ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
AND (ROUTINE_NAME LIKE 'gl_shared_%' OR ROUTINE_NAME IN ('gl_post_apv','gl_apv_is_legacy','gl_post_direct_payment','gl_account_id'));
SELECT id,code,name,status,is_deleted FROM account_type WHERE code IN ('201-111','100-004');
