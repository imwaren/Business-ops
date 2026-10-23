-- Scoped refund APV definitions only. No posting/rebuild/backfill calls.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DELIMITER $$
DROP PROCEDURE IF EXISTS assert_refund_apv_posting_install$$
CREATE PROCEDURE assert_refund_apv_posting_install()
BEGIN
    IF DATABASE() NOT IN ('mytemr_diagnostic_uat','mytemr_diagnostic_mobile_uat','mytemr_diagnostic_mactan_uat','mytemr_diagnostic_conso_uat','mytemr_diagnostic_mantlewood_uat') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong schema/environment for refund APV installer';
    END IF;
    IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()
        AND ROUTINE_NAME IN ('gl_patient_display_name','gl_apv_is_legacy','gl_post_apv','gl_clear','gl_remark_context','gl_remark_append','gl_transaction_item_names')) <> 7 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing existing APV/remark prerequisites; stop and reconcile';
    END IF;
END$$
CALL assert_refund_apv_posting_install()$$
DROP PROCEDURE assert_refund_apv_posting_install$$
DELIMITER ;

DELIMITER $$
DROP FUNCTION IF EXISTS gl_remark_context$$
CREATE FUNCTION gl_remark_context(p_source VARCHAR(250), p_source_id INT, p_branch_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_transaction_id INT DEFAULT NULL;
    DECLARE v_item_name LONGTEXT DEFAULT NULL;
    DECLARE v_receipt_note_id INT DEFAULT NULL;
    DECLARE v_type VARCHAR(50) DEFAULT NULL;
    DECLARE v_type_id INT DEFAULT NULL;
    DECLARE v_context LONGTEXT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_transaction_id = NULL;
    IF p_source IN ('transaction', 'sales_return', 'payment', 'payment_multiple_method') THEN
        SET v_transaction_id = gl_csr_txn_id(p_source, p_source_id);
    ELSEIF p_source = 'refund' THEN
        SELECT transaction_id INTO v_transaction_id FROM mytemr_master_uat.refund
        WHERE id = p_source_id AND branch_id = gl_branch_id() LIMIT 1;
    ELSEIF p_source = 'non_cash_deposit' THEN
        SELECT transaction_id INTO v_transaction_id FROM non_cash_deposit WHERE id = p_source_id LIMIT 1;
    ELSEIF p_source = 'item_history' THEN
        SELECT NULLIF(TRIM(i.name), '') INTO v_item_name
        FROM item_history ih LEFT JOIN mytemr_master_uat.item i ON i.id = ih.item_id
        WHERE ih.id = p_source_id LIMIT 1;
        SET v_transaction_id = gl_item_history_transaction_id(p_source_id);
    ELSEIF p_source = 'receipt_note_item' THEN
        SELECT NULLIF(TRIM(i.name), ''), ri.receipt_note_id INTO v_item_name, v_receipt_note_id
        FROM receipt_note_item ri LEFT JOIN mytemr_master_uat.item i ON i.id = ri.item_id
        WHERE ri.id = p_source_id LIMIT 1;
        IF v_receipt_note_id IS NOT NULL THEN
            SET v_context = CONCAT('receipt_note #', v_receipt_note_id);
        END IF;
    ELSEIF p_source IN ('accounts_payable_voucher', 'accounts_payable_voucher_payment_detail') THEN
        IF p_source = 'accounts_payable_voucher' THEN
            SELECT type, type_id INTO v_type, v_type_id FROM accounts_payable_voucher WHERE id = p_source_id LIMIT 1;
        ELSE
            SELECT v.type, v.type_id INTO v_type, v_type_id FROM accounts_payable_voucher_payment_detail d
            INNER JOIN accounts_payable_voucher v ON v.id = d.accounts_payable_voucher_id
            WHERE d.id = p_source_id LIMIT 1;
        END IF;
        IF v_type = 'receipt_note' THEN
            SET v_item_name = gl_receipt_note_item_names(v_type_id);
        ELSEIF v_type = 'refund' THEN
            -- Refund patient_id is a master identity; transaction_id remains clinic-local.
            SELECT r.transaction_id,
                CONCAT('transaction #', NULLIF(TRIM(CAST(t.transaction_no AS CHAR)), ''),
                    IF(gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix) IS NULL, '',
                        CONCAT(' / Patient: ', gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix))))
            INTO v_transaction_id, v_context
            FROM mytemr_master_uat.refund r
            LEFT JOIN `transaction` t ON t.id = r.transaction_id
            LEFT JOIN mytemr_master_uat.patient p ON p.id = r.patient_id AND p.is_deleted = 0
            WHERE r.id = v_type_id AND r.branch_id = gl_branch_id() LIMIT 1;
            SET v_item_name = gl_transaction_item_names(v_transaction_id);
            RETURN gl_remark_append(v_context,
                IF(v_item_name IS NULL, NULL, CONCAT('Items: ', v_item_name)));
        END IF;
    END IF;
    IF v_item_name IS NOT NULL THEN SET v_context = gl_remark_append(v_context, CONCAT('Items: ', v_item_name)); END IF;
    RETURN gl_remark_append(v_context,
        IF(p_source = 'item_history', gl_transaction_patient_context(v_transaction_id),
            gl_transaction_context(v_transaction_id)));
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

    IF IFNULL(@apv_entry_sync, 0) = 1 THEN LEAVE proc; END IF;
    IF gl_apv_is_legacy(p_id)=1 THEN
        IF NOT EXISTS (SELECT 1 FROM accounts_payable_voucher WHERE id=p_id
            AND is_deleted=0 AND status IN ('approved','open','closed')) THEN
            CALL gl_clear('accounts_payable_voucher',p_id);
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
        LEFT JOIN mytemr_master_uat.account_type a ON a.id = e.account_type_id
        WHERE e.accounts_payable_voucher_id = p_id AND e.is_deleted = 0;
        IF v_count = 0 OR v_invalid > 0 OR v_debit <= 0 OR v_debit <> v_credit
            OR v_amount <= 0 OR v_payable <> v_amount THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid APV entries: balance or payable mismatch';
        END IF;
    END IF;

    CALL gl_clear('accounts_payable_voucher', p_id);
    INSERT INTO general_ledger (branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted)
    SELECT gl_branch_id(), 'accounts_payable_voucher', v.id, e.account_type_id,
        COALESCE(DATE(v.approved_on), DATE(v.added_on)), e.debit, e.credit,
        COALESCE(NULLIF(e.remarks,''), CONCAT('APV #',v.id,' entry #',e.id)),
        COALESCE(v.updated_by,v.added_by), NOW(), 0
    FROM accounts_payable_voucher v
    INNER JOIN accounts_payable_voucher_entry e ON e.accounts_payable_voucher_id = v.id
        AND e.is_deleted = 0
    WHERE v.id = p_id AND v.is_deleted = 0 AND v.status IN ('approved','open','closed');
    IF EXISTS (SELECT 1 FROM accounts_payable_voucher v WHERE v.id=p_id
        AND v.type='direct_payment' AND v.is_deleted=0 AND v.status IN ('approved','open','closed')) THEN
        CALL gl_clear('direct_payment',(SELECT type_id FROM accounts_payable_voucher WHERE id=p_id));
    END IF;
END$$
DELIMITER ;
