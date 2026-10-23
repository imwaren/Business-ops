-- Abort before schema/posting DDL on the wrong DB or a conflicting JE feedback path.
DELIMITER $$
DROP PROCEDURE IF EXISTS assert_shared_fund_install$$
CREATE PROCEDURE assert_shared_fund_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_master','mytemr_master_uat') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Shared funds installer requires a master schema';
 END IF;
 IF EXISTS (SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
  AND EVENT_OBJECT_TABLE IN ('journal_entry','journal_entry_item')
  AND ACTION_STATEMENT REGEXP 'gl_post_journal_entry|gl_je_rebuild')
 OR EXISTS (SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
  AND EVENT_OBJECT_TABLE='general_ledger' AND ACTION_TIMING='AFTER' AND EVENT_MANIPULATION='INSERT') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Review conflicting JE/GL feedback hooks before shared installation';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('petty_cash','cash_advance','liquidation','reimbursement','replenishment','change_fund','direct_payment',
  'accounts_payable_voucher','branch','account_type','journal_entry','journal_entry_item','general_ledger',
  'accounts_payable_voucher_payment','accounts_payable_voucher_payment_detail'))<>15 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Required master fund/APV/accounting tables are missing';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
  AND ((TABLE_NAME IN ('general_ledger','journal_entry') AND COLUMN_NAME IN ('source','source_id','is_migrated'))
   OR (TABLE_NAME='journal_entry_item' AND COLUMN_NAME='type')))<>7 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Install JE/GL source, migrated and item type prerequisites first';
 END IF;
 IF (SELECT COUNT(*) FROM account_type WHERE code IN ('201-111','100-004') AND status='active' AND is_deleted=0)<>2 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing or ambiguous shared AP/cash accounting mapping';
 END IF;
END$$
CALL assert_shared_fund_install()$$
DROP PROCEDURE assert_shared_fund_install$$
DELIMITER ;
