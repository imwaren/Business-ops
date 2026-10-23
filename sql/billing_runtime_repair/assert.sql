DELIMITER $$
DROP PROCEDURE IF EXISTS assert_billing_runtime_install$$
CREATE PROCEDURE assert_billing_runtime_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_master','mytemr_master_uat') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Billing runtime repair requires the selected master database';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
  AND ROUTINE_NAME IN ('gl_account_id','gl_billing_payer_label','gl_billing_mop_code','gl_clear_billing_movement','gl_enrich_remark','gl_remark_append','gl_shared_clear','gl_shared_post_direct_payment'))<>8 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing billing runtime helper prerequisites; stop before installation';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('billing','billing_entry','billing_payment','billing_payment_entry','general_ledger','journal_entry','journal_entry_item','direct_payment','branch','apv_accounting_cutover'))<>10 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing billing/accounting/shared dispatch tables';
 END IF;
END$$
CALL assert_billing_runtime_install()$$
DROP PROCEDURE assert_billing_runtime_install$$
DELIMITER ;
