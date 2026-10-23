DELIMITER $$
DROP FUNCTION IF EXISTS gl_apv_is_legacy$$
CREATE FUNCTION gl_apv_is_legacy(p_id INT)
RETURNS TINYINT READS SQL DATA
BEGIN
    RETURN EXISTS (SELECT 1 FROM apv_accounting_cutover
        WHERE id=1 AND p_id<=legacy_max_apv_id)
        AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher_entry
            WHERE accounts_payable_voucher_id=p_id);
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

DROP PROCEDURE IF EXISTS gl_post_direct_payment$$
CREATE PROCEDURE gl_post_direct_payment(IN p_id INT)
proc: BEGIN
    IF EXISTS (SELECT 1 FROM apv_accounting_cutover WHERE id=1 AND p_id<=legacy_max_direct_payment_id)
        AND EXISTS (SELECT 1 FROM direct_payment WHERE id=p_id AND is_deleted=0 AND status='approved')
        AND NOT EXISTS (SELECT 1 FROM accounts_payable_voucher v
            WHERE v.type='direct_payment' AND v.type_id=p_id AND v.is_deleted=0
            AND v.status IN ('approved','open','closed') AND gl_apv_is_legacy(v.id)=0) THEN
        LEAVE proc;
    END IF;
    CALL gl_clear('direct_payment', p_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_direct_payment_entry_ai$$

DROP TRIGGER IF EXISTS trg_gl_direct_payment_entry_au$$

DROP TRIGGER IF EXISTS trg_gl_direct_payment_entry_ad$$

DROP TRIGGER IF EXISTS trg_gl_apv_entry_ai$$
CREATE TRIGGER trg_gl_apv_entry_ai AFTER INSERT ON accounts_payable_voucher_entry
FOR EACH ROW
BEGIN
    CALL gl_post_apv(NEW.accounts_payable_voucher_id);
END$$


DROP TRIGGER IF EXISTS trg_gl_apv_entry_au$$
CREATE TRIGGER trg_gl_apv_entry_au AFTER UPDATE ON accounts_payable_voucher_entry
FOR EACH ROW
BEGIN
    CALL gl_post_apv(NEW.accounts_payable_voucher_id);
    IF OLD.accounts_payable_voucher_id <> NEW.accounts_payable_voucher_id THEN
        CALL gl_post_apv(OLD.accounts_payable_voucher_id);
    END IF;
END$$


DROP TRIGGER IF EXISTS trg_gl_apv_entry_ad$$
CREATE TRIGGER trg_gl_apv_entry_ad AFTER DELETE ON accounts_payable_voucher_entry
FOR EACH ROW
BEGIN
    CALL gl_post_apv(OLD.accounts_payable_voucher_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_apv_legacy_bu$$
CREATE TRIGGER trg_gl_apv_legacy_bu BEFORE UPDATE ON accounts_payable_voucher
FOR EACH ROW
BEGIN
    IF IFNULL(@apv_entry_sync,0)<>1 AND gl_apv_is_legacy(OLD.id)=1 AND (
        NOT (NEW.amount <=> OLD.amount) OR NOT (NEW.withholding_tax <=> OLD.withholding_tax)
        OR NOT (NEW.type <=> OLD.type) OR NOT (NEW.type_id <=> OLD.type_id)
        OR NOT (NEW.due_date <=> OLD.due_date) OR NOT (NEW.added_on <=> OLD.added_on)
        OR (NEW.status IN ('approved','open','closed') AND OLD.status NOT IN ('approved','open','closed'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Legacy APV needs entries before approval or financial changes';
    END IF;
END$$

DELIMITER ;
