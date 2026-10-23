-- Selected master only. Replaces the deposit poster, no data backfill.
DELIMITER $$

DROP PROCEDURE IF EXISTS gl_post_billing_payment_deposit$$

CREATE PROCEDURE gl_post_billing_payment_deposit(IN p_id INT)
post_deposit: BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_branch INT;
    DECLARE v_branch_amount DECIMAL(20,4);
    DECLARE v_total DECIMAL(20,4) DEFAULT 0;
    DECLARE v_amount DECIMAL(20,4) DEFAULT 0;
    DECLARE v_cumulative DECIMAL(20,4) DEFAULT 0;
    DECLARE v_allocated DECIMAL(20,4) DEFAULT 0;
    DECLARE v_share DECIMAL(20,4) DEFAULT 0;
    DECLARE v_account INT;
    DECLARE v_cash_account INT;
    DECLARE v_date DATE;
    DECLARE v_added_by INT;
    DECLARE v_je_id INT;
    DECLARE shares CURSOR FOR
        SELECT be.branch_id, SUM(GREATEST(0, IFNULL(bpe.amount, 0)))
        FROM billing_payment_deposit dep
        INNER JOIN billing_payment_entry bpe ON bpe.billing_payment_id = dep.billing_payment_id
            AND bpe.is_deleted = 0
        INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
        WHERE dep.id = p_id AND IFNULL(be.branch_id, 0) > 0
        GROUP BY be.branch_id HAVING SUM(GREATEST(0, IFNULL(bpe.amount, 0))) > 0
        ORDER BY be.branch_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    -- Stored deposit.amount is already net of EWT: never gross it up or deduct EWT again.
    IF EXISTS (SELECT 1 FROM billing_payment_deposit dep
        INNER JOIN billing_payment bp ON bp.id = dep.billing_payment_id AND bp.is_deleted = 0
        WHERE dep.id = p_id AND dep.is_deleted = 0 AND dep.amount > 0) THEN
        SELECT dep.amount, dep.account_type_id,
            COALESCE(DATE(dep.added_on), bp.paid_date, bp.date), bp.added_by
        INTO v_amount, v_account, v_date, v_added_by
        FROM billing_payment_deposit dep INNER JOIN billing_payment bp ON bp.id = dep.billing_payment_id
        WHERE dep.id = p_id;
        SET v_cash_account = gl_account_id('100-004');
        IF v_cash_account IS NULL OR NOT EXISTS (SELECT 1 FROM account_type
            WHERE id = v_account AND parent_id IS NULL AND is_deleted = 0) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing or invalid billing deposit account mapping';
        END IF;
        SELECT IFNULL(SUM(GREATEST(0, IFNULL(bpe.amount, 0))), 0) INTO v_total
        FROM billing_payment_deposit dep
        INNER JOIN billing_payment_entry bpe ON bpe.billing_payment_id = dep.billing_payment_id
            AND bpe.is_deleted = 0
        INNER JOIN billing_entry be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
        WHERE dep.id = p_id AND IFNULL(be.branch_id, 0) > 0;
        IF v_total <= 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Billing deposit requires active branch allocations';
        END IF;
    END IF;

    UPDATE journal_entry_item i INNER JOIN journal_entry j ON j.id = i.journal_entry_id
    SET i.is_deleted = 1, i.updated_on = NOW()
    WHERE j.source = 'billing_payment_deposit' AND j.source_id = p_id
        AND IFNULL(j.is_migrated, 0) = 0 AND i.is_deleted = 0;
    UPDATE journal_entry SET is_deleted = 1, updated_on = NOW()
    WHERE source = 'billing_payment_deposit' AND source_id = p_id
        AND IFNULL(is_migrated, 0) = 0 AND is_deleted = 0;
    UPDATE general_ledger SET is_deleted = 1, updated_on = NOW()
    WHERE source = 'billing_payment_deposit' AND source_id = p_id
        AND IFNULL(is_migrated, 0) = 0 AND is_deleted = 0;
    IF v_amount <= 0 THEN LEAVE post_deposit; END IF;

    OPEN shares;
    share_loop: LOOP
        FETCH shares INTO v_branch, v_branch_amount;
        IF v_done = 1 THEN LEAVE share_loop; END IF;
        SET v_cumulative = v_cumulative + v_branch_amount;
        SET v_share = ROUND(v_amount * v_cumulative / v_total, 4) - v_allocated;
        SET v_allocated = v_allocated + v_share;
        IF v_share > 0 THEN
            INSERT INTO general_ledger (branch_id, source, source_id, account_type_id, date,
                debit, credit, remark, added_by, added_on, is_deleted)
            VALUES (v_branch, 'billing_payment_deposit', p_id, v_account, v_date,
                v_share, 0, CONCAT('Deposit #', p_id), v_added_by, NOW(), 0),
                (v_branch, 'billing_payment_deposit', p_id, v_cash_account, v_date,
                0, v_share, CONCAT('Deposit from cash/check on hand #', p_id), v_added_by, NOW(), 0);
        END IF;
    END LOOP;
    CLOSE shares;

    INSERT INTO journal_entry (cost_center_id, date, type, remarks, status, total_debit,
        total_credit, source, source_id, added_by, added_on, is_deleted)
    SELECT IFNULL(MIN(gl.branch_id), 0), MIN(gl.date), 'billing_payment_deposit',
        gl_enrich_remark('billing_payment_deposit', p_id, NULL, CONCAT('billing_payment_deposit #', p_id)),
        'approved', SUM(gl.debit), SUM(gl.credit), 'billing_payment_deposit', p_id,
        IFNULL(MIN(gl.added_by), 0), NOW(), 0
    FROM general_ledger gl WHERE gl.source = 'billing_payment_deposit' AND gl.source_id = p_id
        AND gl.is_deleted = 0 AND IFNULL(gl.is_migrated, 0) = 0 HAVING COUNT(*) > 0;
    IF ROW_COUNT() > 0 THEN
        SET v_je_id = LAST_INSERT_ID();
        INSERT INTO journal_entry_item (journal_entry_id, account_type_id, debit, credit,
            remarks, added_by, added_on, is_deleted)
        SELECT v_je_id, gl.account_type_id, gl.debit, gl.credit, gl.remark,
            IFNULL(gl.added_by, 0), NOW(), 0
        FROM general_ledger gl WHERE gl.source = 'billing_payment_deposit' AND gl.source_id = p_id
            AND gl.is_deleted = 0 AND IFNULL(gl.is_migrated, 0) = 0 ORDER BY gl.id;
    END IF;
END$$

DELIMITER ;
