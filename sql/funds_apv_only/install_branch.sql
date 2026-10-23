-- Fund accounting through APVs only. Definitions only; no history cleanup/repost.
DELIMITER $$
DROP TRIGGER IF EXISTS trg_gl_change_fund_ai$$
DROP TRIGGER IF EXISTS trg_gl_change_fund_au$$
DROP TRIGGER IF EXISTS trg_gl_change_fund_ad$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_ai$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_au$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_ad$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_ai$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_au$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_ad$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_ai$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_au$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_ad$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_ai$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_au$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_ad$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_detail_ai$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_detail_au$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_detail_ad$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_item_ai$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_item_au$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_item_ad$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_item_ai$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_item_au$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_item_ad$$

DROP PROCEDURE IF EXISTS gl_post_change_fund$$

CREATE PROCEDURE gl_post_change_fund(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$

DROP PROCEDURE IF EXISTS gl_post_petty_cash$$

CREATE PROCEDURE gl_post_petty_cash(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$

DROP PROCEDURE IF EXISTS gl_post_liquidation$$

CREATE PROCEDURE gl_post_liquidation(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$

DROP PROCEDURE IF EXISTS gl_post_reimbursement$$

CREATE PROCEDURE gl_post_reimbursement(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$

DROP PROCEDURE IF EXISTS gl_post_replenishment$$

CREATE PROCEDURE gl_post_replenishment(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$

DROP PROCEDURE IF EXISTS gl_bf_drain$$

CREATE PROCEDURE gl_bf_drain(IN p_kind VARCHAR(64))
BEGIN
    DECLARE v_id INT;
    DECLARE v_cnt INT DEFAULT 0;

    SELECT COUNT(*) INTO v_cnt FROM gl_bf_ids;
    WHILE v_cnt > 0 DO
        SELECT id INTO v_id FROM gl_bf_ids LIMIT 1;
        CASE p_kind
            WHEN 'csr_mop' THEN CALL gl_post_csr_mop(v_id);
            WHEN 'csr_discount' THEN CALL gl_post_csr_discount(v_id);
            WHEN 'refund' THEN CALL gl_post_refund(v_id);
            WHEN 'sales_return' THEN CALL gl_post_sales_return(v_id);
            WHEN 'unbilled' THEN CALL gl_post_unbilled(v_id);
            WHEN 'txn_je' THEN CALL gl_rebuild_txn_je(v_id);
            WHEN 'item_history' THEN CALL gl_post_item_history(v_id);
            WHEN 'receipt_note' THEN CALL gl_post_receipt_note(v_id);
            WHEN 'purchase_order' THEN CALL gl_post_purchase_order(v_id);
            WHEN 'purchase_request' THEN CALL gl_post_purchase_request(v_id);
            WHEN 'apv' THEN CALL gl_post_apv(v_id);
            WHEN 'apv_payment_detail' THEN CALL gl_post_apv_payment_detail(v_id);
            WHEN 'direct_payment' THEN CALL gl_post_direct_payment(v_id);
            WHEN 'journal_entry' THEN CALL gl_post_journal_entry(v_id);
            WHEN 'cash_deposit' THEN CALL gl_post_cash_deposit(v_id);
            WHEN 'non_cash_deposit' THEN CALL gl_post_non_cash_deposit(v_id);
            ELSE SET v_id = v_id;
        END CASE;
        DELETE FROM gl_bf_ids WHERE id = v_id;
        SET v_cnt = v_cnt - 1;
    END WHILE;
END$$

DROP PROCEDURE IF EXISTS gl_backfill_all$$

CREATE PROCEDURE gl_backfill_all()
BEGIN
    DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
    CREATE TEMPORARY TABLE gl_bf_ids (
        id INT NOT NULL,
        PRIMARY KEY (id)
    );

    INSERT INTO gl_bf_ids SELECT id FROM payment_multiple_method WHERE is_deleted = 0;
    CALL gl_bf_drain('csr_mop');

    INSERT INTO gl_bf_ids SELECT id FROM payment WHERE is_deleted = 0;
    CALL gl_bf_drain('csr_discount');

    INSERT INTO gl_bf_ids
    SELECT refund.id
    FROM mytemr_master.refund
    WHERE refund.is_deleted = 0
        AND refund.branch_id = gl_branch_id();
    CALL gl_bf_drain('refund');

    INSERT INTO gl_bf_ids
    SELECT txn.id
    FROM `transaction` AS txn
    WHERE txn.is_deleted = 1
        AND DATE(IFNULL(txn.updated_on, NOW())) <> DATE(txn.transaction_date);
    CALL gl_bf_drain('sales_return');

    INSERT INTO gl_bf_ids SELECT id FROM `transaction` WHERE is_deleted = 0;
    CALL gl_bf_drain('unbilled');

    INSERT INTO gl_bf_ids
    SELECT id FROM item_history
    WHERE is_deleted = 0
        AND activity IN (
            'perform', 'Machine Run', 'lab supplies', 'pharmacy',
            'Transfer Out', 'Transfer In', 'Adjustment', 'Blindsheet'
        );
    CALL gl_bf_drain('item_history');

    INSERT INTO gl_bf_ids SELECT id FROM receipt_note WHERE is_deleted = 0;
    CALL gl_bf_drain('receipt_note');

    INSERT INTO gl_bf_ids SELECT id FROM purchase_order WHERE is_deleted = 0;
    CALL gl_bf_drain('purchase_order');

    INSERT INTO gl_bf_ids SELECT id FROM purchase_request WHERE is_deleted = 0;
    CALL gl_bf_drain('purchase_request');

    INSERT INTO gl_bf_ids SELECT id FROM accounts_payable_voucher WHERE is_deleted = 0;
    CALL gl_bf_drain('apv');

    INSERT INTO gl_bf_ids
    SELECT id FROM accounts_payable_voucher_payment_detail WHERE is_deleted = 0;
    CALL gl_bf_drain('apv_payment_detail');

    INSERT INTO gl_bf_ids SELECT id FROM direct_payment WHERE is_deleted = 0;
    CALL gl_bf_drain('direct_payment');

    INSERT INTO gl_bf_ids
    SELECT id FROM journal_entry WHERE is_deleted = 0 AND IFNULL(source, '') = '';
    CALL gl_bf_drain('journal_entry');

    INSERT INTO gl_bf_ids SELECT id FROM cash_deposit WHERE is_deleted = 0;
    CALL gl_bf_drain('cash_deposit');

    INSERT INTO gl_bf_ids SELECT id FROM non_cash_deposit WHERE is_deleted = 0;
    CALL gl_bf_drain('non_cash_deposit');

    DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
END$$
DELIMITER ;
