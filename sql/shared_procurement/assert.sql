DELIMITER $$
DROP PROCEDURE IF EXISTS assert_shared_procurement_install$$
CREATE PROCEDURE assert_shared_procurement_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_master','mytemr_master_uat') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Shared procurement requires the selected master database';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
  AND ROUTINE_NAME IN ('gl_shared_clear','gl_shared_je','gl_account_id','gl_post_apv'))<>4 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Install current shared-fund accounting prerequisites first';
 END IF;
 IF (SELECT COUNT(*) FROM account_type WHERE code IN ('104-030','106-000','201-110','201-111') AND status='active' AND is_deleted=0)<>4 OR (SELECT COUNT(DISTINCT code) FROM account_type WHERE code IN ('104-030','106-000','201-110','201-111') AND status='active' AND is_deleted=0)<>4 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing procurement inventory/VAT/transitory/payable account mappings';
 END IF;
 IF EXISTS (SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
  AND EVENT_OBJECT_TABLE IN ('purchase_request','purchase_request_item','purchase_order','purchase_order_item','receipt_note','receipt_note_item')
  AND ACTION_STATEMENT REGEXP 'gl_post_|gl_shared_post_'
  AND TRIGGER_NAME NOT LIKE 'trg_gl_shared_%') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conflicting existing procurement posting hooks; reconcile before installation';
 END IF;
END$$
CALL assert_shared_procurement_install()$$
DROP PROCEDURE assert_shared_procurement_install$$
DELIMITER ;
