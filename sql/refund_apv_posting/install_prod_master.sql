-- Scoped refund APV definitions only. No posting/rebuild/backfill calls.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DELIMITER $$
DROP PROCEDURE IF EXISTS assert_refund_apv_posting_install$$
CREATE PROCEDURE assert_refund_apv_posting_install()
BEGIN
    IF DATABASE() NOT IN ('mytemr_master') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong schema/environment for refund APV installer';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
        AND ROUTINE_NAME IN ('gl_patient_display_name','gl_apv_is_legacy','gl_post_apv','gl_shared_clear','gl_master_apv_context','gl_billing_transaction_no')) <> 6 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing existing APV/remark prerequisites; stop and reconcile';
    END IF;
END$$
CALL assert_refund_apv_posting_install()$$
DROP PROCEDURE assert_refund_apv_posting_install$$
DELIMITER ;

DELIMITER $$
DROP FUNCTION IF EXISTS gl_master_apv_context$$
CREATE FUNCTION gl_master_apv_context(p_src VARCHAR(250),p_id INT,p_base LONGTEXT)
RETURNS LONGTEXT READS SQL DATA
BEGIN
 DECLARE rid INT DEFAULT NULL;
 DECLARE bid INT DEFAULT NULL;
 DECLARE tid INT DEFAULT NULL;
 DECLARE patient_name LONGTEXT DEFAULT NULL;
 DECLARE CONTINUE HANDLER FOR NOT FOUND SET rid=NULL;
 IF p_src='receipt_note_item' THEN
  SELECT receipt_note_id INTO rid FROM receipt_note_item WHERE id=p_id LIMIT 1;
  IF rid IS NULL OR LOCATE(CONCAT('receipt_note #',rid),IFNULL(p_base,''))>0 THEN RETURN p_base; END IF;
  RETURN CONCAT_WS(' | ',NULLIF(p_base,''),CONCAT('receipt_note #',rid));
 END IF;
 IF p_src='accounts_payable_voucher' THEN
  SELECT r.id,r.branch_id,r.transaction_id,gl_patient_display_name(p.last_name,p.first_name,p.middle_name,p.suffix) INTO rid,bid,tid,patient_name
  FROM accounts_payable_voucher v JOIN refund r ON r.id=v.type_id AND r.branch_id=v.cost_center_id
  LEFT JOIN patient p ON p.id=r.patient_id AND p.is_deleted=0
  WHERE v.id=p_id AND v.type='refund' LIMIT 1;
 ELSEIF p_src='accounts_payable_voucher_payment_detail' THEN
  SELECT r.id,r.branch_id,r.transaction_id,gl_patient_display_name(p.last_name,p.first_name,p.middle_name,p.suffix) INTO rid,bid,tid,patient_name
  FROM accounts_payable_voucher_payment_detail d JOIN accounts_payable_voucher v ON v.id=d.accounts_payable_voucher_id
  JOIN refund r ON r.id=v.type_id AND r.branch_id=v.cost_center_id
  LEFT JOIN patient p ON p.id=r.patient_id AND p.is_deleted=0
  WHERE d.id=p_id AND v.type='refund' LIMIT 1;
 END IF;
 RETURN IF(rid IS NULL,p_base,CONCAT(p_base,' | refund #',rid,' | branch #',bid,
  ' | transaction #',COALESCE(gl_billing_transaction_no(bid,tid),CAST(tid AS CHAR)),
  IF(patient_name IS NULL,'',CONCAT(' / Patient: ',patient_name))));
END$$

DROP PROCEDURE IF EXISTS gl_post_apv$$
CREATE PROCEDURE gl_post_apv(IN p_id INT)
proc: BEGIN
    DECLARE v_amount DECIMAL(13,4);
    DECLARE v_debit DECIMAL(20,4);
    DECLARE v_credit DECIMAL(20,4);
    DECLARE v_payable DECIMAL(20,4);
    DECLARE v_count INT;
    DECLARE v_invalid INT;

    IF NOT EXISTS (SELECT 1 FROM accounts_payable_voucher s JOIN branch b ON b.id=s.cost_center_id
        WHERE s.id=p_id AND (b.type='shared' OR s.type='refund') AND b.is_deleted=0) THEN LEAVE proc; END IF;
    IF IFNULL(@apv_entry_sync, 0) = 1 THEN LEAVE proc; END IF;
    IF gl_apv_is_legacy(p_id)=1 THEN
        IF NOT EXISTS (SELECT 1 FROM accounts_payable_voucher WHERE id=p_id
            AND is_deleted=0 AND status IN ('approved','open','closed')) THEN
            CALL gl_shared_clear('accounts_payable_voucher',p_id);
        END IF;
        LEAVE proc;
    END IF;
    IF EXISTS (SELECT 1 FROM accounts_payable_voucher
        WHERE id = p_id AND is_deleted = 0 AND status IN ('approved','open','closed')) THEN
        SELECT amount INTO v_amount FROM accounts_payable_voucher WHERE id = p_id;
        SELECT COUNT(*), COALESCE(SUM(e.debit),0), COALESCE(SUM(e.credit),0),
            COALESCE(SUM(CASE WHEN a.code = '201-111' THEN e.credit-e.debit ELSE 0 END),0),
            COALESCE(SUM(CASE WHEN a.id IS NULL OR a.is_deleted <> 0 OR a.status <> 'active'
                OR e.debit IS NULL OR e.credit IS NULL OR e.debit < 0 OR e.credit < 0
                OR ((e.debit > 0) = (e.credit > 0)) THEN 1 ELSE 0 END),0)
        INTO v_count, v_debit, v_credit, v_payable, v_invalid
        FROM accounts_payable_voucher_entry e
        LEFT JOIN account_type a ON a.id = e.account_type_id
        WHERE e.accounts_payable_voucher_id = p_id AND e.is_deleted = 0;
        IF v_count = 0 OR v_invalid > 0 OR v_debit <= 0 OR v_debit <> v_credit
            OR v_amount <= 0 OR v_payable <> v_amount THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid APV entries: balance or payable mismatch';
        END IF;
    END IF;

    CALL gl_shared_clear('accounts_payable_voucher', p_id);
    INSERT INTO general_ledger (branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted)
    SELECT CASE WHEN EXISTS (SELECT 1 FROM branch WHERE id=v.cost_center_id AND type='shared') THEN 0 ELSE v.cost_center_id END, 'accounts_payable_voucher', v.id, e.account_type_id,
        COALESCE(DATE(v.approved_on), DATE(v.added_on)), e.debit, e.credit,
        COALESCE(NULLIF(e.remarks,''), CONCAT('APV #',v.id,' entry #',e.id)),
        COALESCE(v.updated_by,v.added_by), NOW(), 0
    FROM accounts_payable_voucher v
    INNER JOIN accounts_payable_voucher_entry e ON e.accounts_payable_voucher_id = v.id
        AND e.is_deleted = 0
    WHERE v.id = p_id AND v.is_deleted = 0 AND v.status IN ('approved','open','closed');
    IF EXISTS (SELECT 1 FROM accounts_payable_voucher v WHERE v.id=p_id
        AND v.type='direct_payment' AND v.is_deleted=0 AND v.status IN ('approved','open','closed')) THEN
        CALL gl_shared_clear('direct_payment',(SELECT type_id FROM accounts_payable_voucher WHERE id=p_id));
    END IF;
    CALL gl_shared_je('accounts_payable_voucher',p_id);
    BEGIN
        DECLARE done INT DEFAULT 0;
        DECLARE did INT;
        DECLARE c CURSOR FOR SELECT id FROM accounts_payable_voucher_payment_detail WHERE accounts_payable_voucher_id=p_id;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
        OPEN c;
        refresh_payments: LOOP
            FETCH c INTO did;
            IF done=1 THEN LEAVE refresh_payments; END IF;
            CALL gl_shared_post_apvpd(did);
        END LOOP;
        CLOSE c;
    END;
END$$
DELIMITER ;
