-- Run in the selected master database only. Does not repost historical data.
DELIMITER $$
-- BEGIN BILLING BASE HELPER PREREQUISITE
DROP PROCEDURE IF EXISTS assert_billing_base_helper$$
CREATE PROCEDURE assert_billing_base_helper()
BEGIN
 IF NOT EXISTS (SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_remark_append' AND ROUTINE_TYPE='FUNCTION') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Install gl_remark_append before billing journal helpers';
 END IF;
END$$
CALL assert_billing_base_helper()$$
DROP PROCEDURE assert_billing_base_helper$$
-- END BILLING BASE HELPER PREREQUISITE

DROP FUNCTION IF EXISTS gl_journal_base_remark$$
CREATE FUNCTION gl_journal_base_remark(p_source VARCHAR(250), p_source_id INT, p_base LONGTEXT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_base LONGTEXT DEFAULT NULL;
    DECLARE v_control_no TEXT DEFAULT NULL;
    DECLARE v_billing_id INT DEFAULT NULL;
    DECLARE v_invoice_no TEXT DEFAULT NULL;
    DECLARE v_not_found INT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_not_found = 1;

    SET v_base = COALESCE(NULLIF(p_base, ''), CONCAT(p_source, ' #', p_source_id));
    IF p_source = 'credit_memo' THEN
        SELECT NULLIF(TRIM(control_no), ''), billing_id INTO v_control_no, v_billing_id
        FROM credit_memo WHERE id = p_source_id LIMIT 1;
        IF v_control_no IS NOT NULL THEN
            SET v_base = gl_remark_append(v_base, CONCAT('Credit memo #', v_control_no));
        END IF;
    ELSEIF p_source = 'debit_memo' THEN
        SELECT NULLIF(TRIM(control_no), ''), billing_id INTO v_control_no, v_billing_id
        FROM debit_memo WHERE id = p_source_id LIMIT 1;
        IF v_control_no IS NOT NULL THEN
            SET v_base = gl_remark_append(v_base, CONCAT('Debit memo #', v_control_no));
        END IF;
    ELSE
        RETURN v_base;
    END IF;

    IF IFNULL(v_billing_id, 0) > 0 THEN
        SET v_base = gl_remark_append(v_base, CONCAT('Billing #', v_billing_id));
        SELECT NULLIF(TRIM(invoice_no), '') INTO v_invoice_no
        FROM billing WHERE id = v_billing_id LIMIT 1;
        IF v_invoice_no IS NOT NULL THEN
            SET v_base = gl_remark_append(v_base, CONCAT('Invoice #', v_invoice_no));
        END IF;
    END IF;
    RETURN v_base;
END$$

DROP FUNCTION IF EXISTS gl_billing_payment_journal_remark$$
CREATE FUNCTION gl_billing_payment_journal_remark(p_source VARCHAR(250), p_source_id INT, p_base LONGTEXT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_remarks LONGTEXT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_remarks = NULL;
    IF p_source <> 'billing_payment' OR p_source IS NULL THEN RETURN p_base; END IF;
    SELECT TRIM(remarks) INTO v_remarks FROM billing_payment WHERE id = p_source_id LIMIT 1;
    IF v_remarks IS NULL OR v_remarks NOT REGEXP '[^[:space:]]' THEN RETURN p_base; END IF;
    RETURN CONCAT_WS(' | ', NULLIF(p_base, ''), v_remarks);
END$$

DROP PROCEDURE IF EXISTS gl_je_from_gl$$

CREATE PROCEDURE gl_je_from_gl(
    IN p_source VARCHAR(250),
    IN p_source_id INT,
    IN p_account_type_id INT,
    IN p_date DATE,
    IN p_debit DECIMAL(13,4),
    IN p_credit DECIMAL(13,4),
    IN p_remark LONGTEXT,
    IN p_added_by INT,
    IN p_cost_center_id INT
)
BEGIN
    DECLARE v_je_id INT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_je_id = NULL;

    IF IFNULL(p_source, '') <> '' AND p_source <> 'journal_entry' THEN
        SELECT journal_entry.id INTO v_je_id
        FROM journal_entry
        WHERE journal_entry.source = p_source
            AND journal_entry.source_id = p_source_id
            AND journal_entry.is_deleted = 0
            AND (p_source <> 'billing_payment' OR IFNULL(journal_entry.is_migrated, 0) = 0)
        LIMIT 1;

        IF v_je_id IS NULL THEN
            INSERT INTO journal_entry (
                cost_center_id, date, type, remarks, status,
                total_debit, total_credit, source, source_id,
                added_by, added_on, is_deleted
            ) VALUES (
                IFNULL(p_cost_center_id, 0),
                p_date,
                LEFT(p_source, 64),
                gl_billing_payment_journal_remark(p_source, p_source_id, gl_enrich_remark(p_source, p_source_id, NULL, gl_journal_base_remark(p_source, p_source_id, CONCAT(p_source, ' #', p_source_id)))),
                'approved',
                IFNULL(p_debit, 0),
                IFNULL(p_credit, 0),
                p_source,
                p_source_id,
                IFNULL(p_added_by, 0),
                NOW(),
                0
            );
            SET v_je_id = LAST_INSERT_ID();
        END IF;

        UPDATE journal_entry SET remarks = gl_billing_payment_journal_remark(p_source, p_source_id, gl_enrich_remark(p_source, p_source_id, NULL, gl_journal_base_remark(p_source, p_source_id, CONCAT(p_source, ' #', p_source_id)))) WHERE id = v_je_id AND (p_source <> 'billing_payment' OR IFNULL(is_migrated, 0) = 0);

        INSERT INTO journal_entry_item (
            journal_entry_id, account_type_id,
            debit, credit, remarks, added_by, added_on, is_deleted
        ) VALUES (
            v_je_id,
            p_account_type_id,
            IFNULL(p_debit, 0),
            IFNULL(p_credit, 0),
            p_remark,
            IFNULL(p_added_by, 0),
            NOW(),
            0
        );
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_je_rebuild$$

CREATE PROCEDURE gl_je_rebuild(IN p_source VARCHAR(250), IN p_source_id INT)
BEGIN
    DECLARE v_je_id INT DEFAULT NULL;
    DECLARE v_gl_cnt INT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_je_id = NULL;

    IF IFNULL(p_source, '') <> '' AND p_source <> 'journal_entry' THEN
        -- Covered master posters rebuild one active journal set, retaining history.
        IF p_source IN ('billing_entry', 'billing_payment', 'billing_payment_deposit',
            'check_deposit', 'credit_memo', 'debit_memo') THEN
            UPDATE journal_entry_item AS item
            INNER JOIN journal_entry AS je ON je.id = item.journal_entry_id
            SET item.is_deleted = 1, item.updated_on = NOW()
            WHERE je.source = p_source AND je.source_id = p_source_id
                AND item.is_deleted = 0
                AND (p_source <> 'billing_payment' OR IFNULL(je.is_migrated, 0) = 0);
            UPDATE journal_entry
            SET is_deleted = 1, updated_on = NOW()
            WHERE source = p_source AND source_id = p_source_id AND is_deleted = 0
                AND (p_source <> 'billing_payment' OR IFNULL(is_migrated, 0) = 0);
        END IF;

        SELECT COUNT(*) INTO v_gl_cnt
        FROM general_ledger
        WHERE source = p_source
            AND source_id = p_source_id
            AND is_deleted = 0
            AND (p_source <> 'billing_payment' OR IFNULL(is_migrated, 0) = 0);

        IF IFNULL(v_gl_cnt, 0) > 0 THEN
            SELECT journal_entry.id INTO v_je_id
            FROM journal_entry
            WHERE journal_entry.source = p_source
                AND journal_entry.source_id = p_source_id
                AND journal_entry.is_deleted = 0
                AND (p_source <> 'billing_payment' OR IFNULL(journal_entry.is_migrated, 0) = 0)
            LIMIT 1;

            IF v_je_id IS NULL THEN
                INSERT INTO journal_entry (
                    cost_center_id, date, type, remarks, status,
                    total_debit, total_credit, source, source_id,
                    added_by, added_on, is_deleted
                )
                SELECT IFNULL(MIN(gl.branch_id), 0),
                    MIN(gl.date),
                    LEFT(p_source, 64),
                    gl_billing_payment_journal_remark(p_source, p_source_id, gl_enrich_remark(p_source, p_source_id, NULL, gl_journal_base_remark(p_source, p_source_id, CONCAT(p_source, ' #', p_source_id)))),
                    'approved',
                    IFNULL(SUM(gl.debit), 0),
                    IFNULL(SUM(gl.credit), 0),
                    p_source,
                    p_source_id,
                    IFNULL(MIN(gl.added_by), 0),
                    NOW(),
                    0
                FROM general_ledger AS gl
                WHERE gl.source = p_source
                    AND gl.source_id = p_source_id
                    AND gl.is_deleted = 0
                    AND (p_source <> 'billing_payment' OR IFNULL(gl.is_migrated, 0) = 0);
                SET v_je_id = LAST_INSERT_ID();
            END IF;

            UPDATE journal_entry SET remarks = gl_billing_payment_journal_remark(p_source, p_source_id, gl_enrich_remark(p_source, p_source_id, NULL, gl_journal_base_remark(p_source, p_source_id, CONCAT(p_source, ' #', p_source_id)))) WHERE id = v_je_id AND (p_source <> 'billing_payment' OR IFNULL(is_migrated, 0) = 0);

            INSERT INTO journal_entry_item (
                journal_entry_id, account_type_id,
                debit, credit, remarks, added_by, added_on, is_deleted
            )
            SELECT v_je_id,
                gl.account_type_id,
                IFNULL(gl.debit, 0),
                IFNULL(gl.credit, 0),
                gl.remark,
                IFNULL(gl.added_by, 0),
                NOW(),
                0
            FROM general_ledger AS gl
            WHERE gl.source = p_source
                AND gl.source_id = p_source_id
                AND gl.is_deleted = 0
                AND (p_source <> 'billing_payment' OR IFNULL(gl.is_migrated, 0) = 0)
                AND gl.account_type_id IS NOT NULL
            ORDER BY gl.id;
        END IF;
    END IF;
END$$

DROP FUNCTION IF EXISTS gl_billing_mop_code$$

CREATE FUNCTION gl_billing_mop_code(p_mop VARCHAR(50), p_others VARCHAR(255))
RETURNS VARCHAR(32)
DETERMINISTIC
NO SQL
BEGIN
    DECLARE v_mop VARCHAR(50);
    DECLARE v_other VARCHAR(255);
    SET v_mop = LOWER(TRIM(IFNULL(p_mop, '')));
    SET v_other = LOWER(REPLACE(IFNULL(p_others, ''), ' ', ''));
    IF v_mop IN ('cash', 'check') THEN
        RETURN '100-004';
    END IF;
    IF v_mop = 'online_payment' THEN
        RETURN '102-193';
    END IF;
    IF v_mop = 'others' AND v_other IN ('gcash', 'maya', 'qrph', 'qr') THEN
        RETURN '102-193';
    END IF;
    IF v_mop = 'others' AND v_other IN ('card', 'creditcard', 'debitcard') THEN
        RETURN '102-192';
    END IF;
    RETURN NULL; -- Unknown modes require an explicit active account.
END$$

DROP PROCEDURE IF EXISTS gl_clear_billing_movement$$

CREATE PROCEDURE gl_clear_billing_movement(IN p_source VARCHAR(250), IN p_source_id INT)
BEGIN
    IF p_source NOT IN ('billing_entry', 'billing_payment') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported billing movement source';
    END IF;
    UPDATE journal_entry_item AS item
    INNER JOIN journal_entry AS je ON je.id = item.journal_entry_id
    SET item.is_deleted = 1, item.updated_on = NOW()
    WHERE je.source = p_source AND je.source_id = p_source_id
        AND item.is_deleted = 0
        AND (p_source <> 'billing_payment' OR IFNULL(je.is_migrated, 0) = 0);
    UPDATE journal_entry
    SET is_deleted = 1, updated_on = NOW()
    WHERE source = p_source AND source_id = p_source_id AND is_deleted = 0
        AND (p_source <> 'billing_payment' OR IFNULL(is_migrated, 0) = 0);
    IF p_source = 'billing_payment' THEN
        UPDATE general_ledger SET is_deleted = 1, updated_on = NOW()
        WHERE source = p_source AND source_id = p_source_id AND is_deleted = 0
            AND IFNULL(is_migrated, 0) = 0;
    ELSE
        CALL gl_clear(p_source, p_source_id);
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_billing_payment$$

CREATE PROCEDURE gl_post_billing_payment(IN p_id INT)
post_payment: BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_branch INT;
    DECLARE v_amount DECIMAL(20,4);
    DECLARE v_total DECIMAL(20,4) DEFAULT 0;
    DECLARE v_cumulative DECIMAL(20,4) DEFAULT 0;
    DECLARE v_allocated_wht DECIMAL(20,4) DEFAULT 0;
    DECLARE v_branch_wht DECIMAL(20,4) DEFAULT 0;
    DECLARE v_wht DECIMAL(20,4) DEFAULT 0;
    DECLARE v_account_id INT DEFAULT NULL;
    DECLARE v_wht_account INT DEFAULT NULL;
    DECLARE v_je_id INT DEFAULT NULL;
    DECLARE shares CURSOR FOR
        SELECT be.branch_id, SUM(GREATEST(0, IFNULL(bpe.amount, 0)))
        FROM billing_payment_entry bpe
        INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
        WHERE bpe.billing_payment_id = p_id AND bpe.is_deleted = 0
            AND be.branch_id >= 0
        GROUP BY be.branch_id
        HAVING SUM(GREATEST(0, IFNULL(bpe.amount, 0))) > 0
        ORDER BY be.branch_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    -- Validate before retiring existing postings. Unknown MOPs never become cash silently.
    IF EXISTS (SELECT 1 FROM billing_payment WHERE id = p_id AND is_deleted = 0) THEN
        SELECT COALESCE(
            (SELECT a.id FROM account_type a WHERE a.id = bp.account_type_id
                AND a.is_deleted = 0 AND a.parent_id IS NULL LIMIT 1),
            gl_account_id(gl_billing_mop_code(bp.mop, bp.others))),
            COALESCE((SELECT a.id FROM account_type a WHERE a.id = bp.wht_account
                AND a.is_deleted = 0 AND a.parent_id IS NULL LIMIT 1), gl_account_id('105-000')),
            GREATEST(0, IFNULL(bp.wht_amount, 0))
        INTO v_account_id, v_wht_account, v_wht
        FROM billing_payment bp WHERE bp.id = p_id;
        IF v_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Collection requires a valid account_type_id or recognized MOP mapping';
        END IF;
        IF v_wht > 0 AND v_wht_account IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing collection withholding account mapping';
        END IF;
        IF gl_account_id('102-113') IS NULL OR gl_account_id('102-250') IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing collection AR account mapping';
        END IF;
    END IF;
    CALL gl_clear_billing_movement('billing_payment', p_id);
    IF NOT EXISTS (SELECT 1 FROM billing_payment WHERE id = p_id AND is_deleted = 0) THEN
        LEAVE post_payment;
    END IF;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0,
        'billing_payment',
        billing_payment.id,
        v_account_id,
        COALESCE(billing_payment.paid_date, billing_payment.date, DATE(billing_payment.added_on)),
        GREATEST(0, IFNULL(billing_payment.grand_total, 0) - IFNULL(billing_payment.wht_amount, 0)),
        0,
        CONCAT('Unidentified collection #', billing_payment.id),
        billing_payment.added_by,
        NOW(),
        0
    FROM billing_payment
    WHERE billing_payment.id = p_id
        AND billing_payment.is_deleted = 0
        AND IFNULL(billing_payment.is_unidentified, 0) = 1
        AND GREATEST(0, IFNULL(billing_payment.grand_total, 0) - IFNULL(billing_payment.wht_amount, 0)) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0,
        'billing_payment',
        billing_payment.id,
        v_wht_account,
        COALESCE(billing_payment.paid_date, billing_payment.date, DATE(billing_payment.added_on)),
        billing_payment.wht_amount,
        0,
        CONCAT('Unidentified collection CWT #', billing_payment.id),
        billing_payment.added_by,
        NOW(),
        0
    FROM billing_payment
    WHERE billing_payment.id = p_id
        AND billing_payment.is_deleted = 0
        AND IFNULL(billing_payment.is_unidentified, 0) = 1
        AND IFNULL(billing_payment.wht_amount, 0) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0,
        'billing_payment',
        billing_payment.id,
        gl_account_id('102-250'),
        COALESCE(billing_payment.paid_date, billing_payment.date, DATE(billing_payment.added_on)),
        0,
        billing_payment.grand_total,
        CONCAT('Unidentified collection #', billing_payment.id),
        billing_payment.added_by,
        NOW(),
        0
    FROM billing_payment
    WHERE billing_payment.id = p_id
        AND billing_payment.is_deleted = 0
        AND IFNULL(billing_payment.is_unidentified, 0) = 1
        AND IFNULL(billing_payment.grand_total, 0) > 0;


    IF EXISTS (SELECT 1 FROM billing_payment WHERE id = p_id AND IFNULL(is_unidentified, 0) = 0) THEN
        SELECT IFNULL(SUM(GREATEST(0, IFNULL(bpe.amount, 0))), 0) INTO v_total
        FROM billing_payment_entry bpe
        INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
        WHERE bpe.billing_payment_id = p_id AND bpe.is_deleted = 0 AND be.branch_id >= 0;
        -- Allocation inserts arrive individually. Cap interim WHT until enough AR is allocated.
        SET v_wht = LEAST(v_wht, v_total);
        OPEN shares;
        share_loop: LOOP
            FETCH shares INTO v_branch, v_amount;
            IF v_done = 1 THEN LEAVE share_loop; END IF;
            SET v_cumulative = v_cumulative + v_amount;
            -- Cumulative rounding gives an exact header WHT total and no negative last penny.
            SET v_branch_wht = ROUND(v_wht * v_cumulative / v_total, 4) - v_allocated_wht;
            SET v_allocated_wht = v_allocated_wht + v_branch_wht;
            INSERT INTO general_ledger (branch_id, source, source_id, account_type_id, date,
                debit, credit, remark, added_by, added_on, is_deleted)
            SELECT v_branch, 'billing_payment', bp.id, v_account_id,
                COALESCE(bp.paid_date, bp.date, DATE(bp.added_on)),
                v_amount - v_branch_wht, 0,
                CONCAT(gl_billing_payer_label(bp.charge_to), ' collection #', bp.id),
                bp.added_by, NOW(), 0 FROM billing_payment bp
            WHERE bp.id = p_id AND v_amount - v_branch_wht > 0;
            INSERT INTO general_ledger (branch_id, source, source_id, account_type_id, date,
                debit, credit, remark, added_by, added_on, is_deleted)
            SELECT v_branch, 'billing_payment', bp.id, v_wht_account,
                COALESCE(bp.paid_date, bp.date, DATE(bp.added_on)),
                v_branch_wht, 0,
                CONCAT(gl_billing_payer_label(bp.charge_to), ' collection CWT #', bp.id),
                bp.added_by, NOW(), 0 FROM billing_payment bp
            WHERE bp.id = p_id AND v_branch_wht > 0;
            INSERT INTO general_ledger (branch_id, source, source_id, account_type_id, date,
                debit, credit, remark, added_by, added_on, is_deleted)
            SELECT v_branch, 'billing_payment', bp.id, gl_account_id('102-113'),
                COALESCE(bp.paid_date, bp.date, DATE(bp.added_on)), 0, v_amount,
                CONCAT(gl_billing_payer_label(bp.charge_to), ' collection apply #', bp.id),
                bp.added_by, NOW(), 0 FROM billing_payment bp WHERE bp.id = p_id;
        END LOOP;
        CLOSE shares;
    END IF;

    -- Rebuild only this automated payment journal, preserving migrated/manual journals.
    INSERT INTO journal_entry (cost_center_id, date, type, remarks, status,
        total_debit, total_credit, source, source_id, added_by, added_on, is_deleted)
    SELECT IFNULL(MIN(gl.branch_id), 0), MIN(gl.date), 'billing_payment',
        gl_billing_payment_journal_remark('billing_payment', p_id, gl_enrich_remark('billing_payment', p_id, NULL,
            CONCAT('billing_payment #', p_id))),
        'approved', SUM(gl.debit), SUM(gl.credit), 'billing_payment', p_id,
        IFNULL(MIN(gl.added_by), 0), NOW(), 0
    FROM general_ledger gl WHERE gl.source = 'billing_payment' AND gl.source_id = p_id
        AND gl.is_deleted = 0 AND IFNULL(gl.is_migrated, 0) = 0
    HAVING COUNT(*) > 0;
    IF ROW_COUNT() > 0 THEN
        SET v_je_id = LAST_INSERT_ID();
        INSERT INTO journal_entry_item (journal_entry_id, account_type_id, debit, credit,
            remarks, added_by, added_on, is_deleted)
        SELECT v_je_id, gl.account_type_id, gl.debit, gl.credit, gl.remark,
            IFNULL(gl.added_by, 0), NOW(), 0
        FROM general_ledger gl WHERE gl.source = 'billing_payment' AND gl.source_id = p_id
            AND gl.is_deleted = 0 AND IFNULL(gl.is_migrated, 0) = 0 ORDER BY gl.id;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_payment_ai$$

CREATE TRIGGER trg_gl_billing_payment_ai AFTER INSERT ON billing_payment
FOR EACH ROW
BEGIN
    CALL gl_post_billing_payment(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_payment_au$$

CREATE TRIGGER trg_gl_billing_payment_au AFTER UPDATE ON billing_payment
FOR EACH ROW
BEGIN
    CALL gl_post_billing_payment(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_payment_entry_ai$$
CREATE TRIGGER trg_gl_billing_payment_entry_ai AFTER INSERT ON billing_payment_entry
FOR EACH ROW BEGIN
    CALL gl_post_billing_payment(NEW.billing_payment_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_payment_entry_au$$
CREATE TRIGGER trg_gl_billing_payment_entry_au AFTER UPDATE ON billing_payment_entry
FOR EACH ROW BEGIN
    CALL gl_post_billing_payment(OLD.billing_payment_id);
    IF NOT (OLD.billing_payment_id <=> NEW.billing_payment_id) THEN
        CALL gl_post_billing_payment(NEW.billing_payment_id);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_payment_entry_ad$$
CREATE TRIGGER trg_gl_billing_payment_entry_ad AFTER DELETE ON billing_payment_entry
FOR EACH ROW BEGIN
    CALL gl_post_billing_payment(OLD.billing_payment_id);
END$$

DELIMITER ;
