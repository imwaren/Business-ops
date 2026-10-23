-- Future receipt postings only. No data, triggers, schema or history are changed.
DELIMITER $$
DROP PROCEDURE IF EXISTS assert_receipt_note_vat_install$$
CREATE PROCEDURE assert_receipt_note_vat_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_diagnostic','mytemr_diagnostic_mobile','mytemr_diagnostic_mactan','mytemr_diagnostic_conso','mytemr_diagnostic_mantlewood','mytemr_oakridge_warehouse','mytemr_oakridge_pharmacy') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong receipt VAT target environment/path';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ('gl_account_id','gl_branch_id','gl_clear','gl_csr_txn_id','gl_item_history_transaction_id','gl_je_from_gl','gl_patient_display_name','gl_post_purchase_order','gl_receipt_note_item_names','gl_remark_append','gl_rn_net','gl_rn_vat_amt','gl_rn_vat_type','gl_transaction_context','gl_transaction_item_names','gl_transaction_patient_context')) <> 16 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing receipt VAT helper dependencies; inspect installed accounting path';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ('gl_remark_context','gl_enrich_remark','gl_post_receipt_note')) <> 3 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt accounting path not installed; do not introduce a new posting path';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='receipt_note_item' AND COLUMN_NAME='qty') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt posting requires receipt_note_item.qty';
 END IF;
 IF (SELECT COUNT(*) FROM account_type WHERE code='106-000' AND parent_id IS NULL AND is_deleted=0) <> 1
  OR NOT EXISTS (SELECT 1 FROM account_type WHERE code='106-000' AND parent_id IS NULL AND is_deleted=0 AND status='active')
  OR gl_account_id('106-000') IS NULL THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Require one active root INPUT VAT mapping for code 106-000';
 END IF;
END$$
CALL assert_receipt_note_vat_install()$$
DROP PROCEDURE assert_receipt_note_vat_install$$
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
        SELECT transaction_id INTO v_transaction_id FROM mytemr_master.refund
        WHERE id = p_source_id AND branch_id = gl_branch_id() LIMIT 1;
    ELSEIF p_source = 'non_cash_deposit' THEN
        SELECT transaction_id INTO v_transaction_id FROM non_cash_deposit WHERE id = p_source_id LIMIT 1;
    ELSEIF p_source = 'item_history' THEN
        SELECT NULLIF(TRIM(i.name), '') INTO v_item_name
        FROM item_history ih LEFT JOIN mytemr_master.item i ON i.id = ih.item_id
        WHERE ih.id = p_source_id LIMIT 1;
        SET v_transaction_id = gl_item_history_transaction_id(p_source_id);
    ELSEIF p_source = 'receipt_note_item' THEN
        SELECT NULLIF(TRIM(i.name), ''), ri.receipt_note_id INTO v_item_name, v_receipt_note_id
        FROM receipt_note_item ri LEFT JOIN mytemr_master.item i ON i.id = ri.item_id
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
            FROM mytemr_master.refund r
            LEFT JOIN `transaction` t ON t.id = r.transaction_id
            LEFT JOIN mytemr_master.patient p ON p.id = r.patient_id AND p.is_deleted = 0
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
DROP FUNCTION IF EXISTS gl_enrich_remark$$
CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250), p_source_id INT, p_branch_id INT, p_base LONGTEXT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_context LONGTEXT DEFAULT NULL;
    SET v_context = gl_remark_context(p_source, p_source_id, p_branch_id);
    IF p_source = 'receipt_note_item' AND LOCATE(' | Items: ', v_context) > 0
        AND LOCATE(SUBSTRING_INDEX(v_context, ' | Items: ', -1), IFNULL(p_base, '')) > 0 THEN
        SET v_context = SUBSTRING_INDEX(v_context, ' | Items: ', 1);
    END IF;
    -- Existing item-bearing receipt remarks are already complete; keep their prose.
    IF LEFT(v_context, 7) = 'Items: ' AND LOCATE(SUBSTRING(v_context, 8), IFNULL(p_base, '')) > 0 THEN
        RETURN p_base;
    END IF;
    RETURN gl_remark_append(p_base, v_context);
END$$
DROP PROCEDURE IF EXISTS gl_post_receipt_note$$
CREATE PROCEDURE gl_post_receipt_note(IN p_id INT)
BEGIN
    DECLARE v_po_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM receipt_note_item WHERE receipt_note_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_clear('receipt_note_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'receipt_note_item',
        line.id,
        line.inv_account_id,
        line.txn_date,
        line.net_amt,
        0,
        CONCAT('Goods received: ', line.item_name, ' #', line.id, ' | receipt_note #', line.receipt_note_id),
        line.added_by,
        NOW(),
        0
    FROM (
        SELECT receipt_note_item.id,
            receipt_note_item.receipt_note_id,
            receipt_note_item.added_by,
            COALESCE(NULLIF(item.name, ''), CONCAT('Item #', receipt_note_item.item_id)) AS item_name,
            COALESCE(receipt_note.receive_date, receipt_note.needed_date, DATE(receipt_note.added_on)) AS txn_date,
            COALESCE(receipt_note_item.account_type_id, item.account_type_id, gl_account_id('104-030')) AS inv_account_id,
            gl_rn_net(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS net_amt
        FROM receipt_note_item
        INNER JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
            AND receipt_note.is_deleted = 0
            AND receipt_note.status = 'approved'
        LEFT JOIN mytemr_master.item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
        WHERE receipt_note.id = p_id
            AND receipt_note_item.is_deleted = 0
            AND COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0) <> 0
    ) AS line
    WHERE line.net_amt <> 0
        AND line.inv_account_id IS NOT NULL;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'receipt_note_item',
        line.id,
        line.vat_account_id,
        line.txn_date,
        line.vat_amt,
        0,
        CONCAT('Input VAT goods received: ', line.item_name, ' #', line.id, ' | receipt_note #', line.receipt_note_id),
        line.added_by,
        NOW(),
        0
    FROM (
        SELECT receipt_note_item.id,
            receipt_note_item.receipt_note_id,
            receipt_note_item.added_by,
            COALESCE(NULLIF(item.name, ''), CONCAT('Item #', receipt_note_item.item_id)) AS item_name,
            COALESCE(receipt_note.receive_date, receipt_note.needed_date, DATE(receipt_note.added_on)) AS txn_date,
            gl_account_id('106-000') AS vat_account_id,
            gl_rn_vat_amt(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS vat_amt
        FROM receipt_note_item
        INNER JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
            AND receipt_note.is_deleted = 0
            AND receipt_note.status = 'approved'
        LEFT JOIN mytemr_master.item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
        WHERE receipt_note.id = p_id
            AND receipt_note_item.is_deleted = 0
            AND COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0) <> 0
    ) AS line
    WHERE line.vat_amt <> 0
        AND line.vat_account_id IS NOT NULL;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'receipt_note_item',
        line.id,
        gl_account_id('201-110'),
        line.txn_date,
        0,
        line.net_amt + line.vat_amt,
        CONCAT('AP transitory goods received: ', line.item_name, ' #', line.id, ' | receipt_note #', line.receipt_note_id),
        line.added_by,
        NOW(),
        0
    FROM (
        SELECT receipt_note_item.id,
            receipt_note_item.receipt_note_id,
            receipt_note_item.added_by,
            COALESCE(NULLIF(item.name, ''), CONCAT('Item #', receipt_note_item.item_id)) AS item_name,
            COALESCE(receipt_note.receive_date, receipt_note.needed_date, DATE(receipt_note.added_on)) AS txn_date,
            gl_rn_net(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS net_amt,
            gl_rn_vat_amt(
                COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0),
                receipt_note_item.vat,
                gl_rn_vat_type(receipt_note_item.vat_type, item.vat_type)
            ) AS vat_amt
        FROM receipt_note_item
        INNER JOIN receipt_note ON receipt_note.id = receipt_note_item.receipt_note_id
            AND receipt_note.is_deleted = 0
            AND receipt_note.status = 'approved'
        LEFT JOIN mytemr_master.item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
        WHERE receipt_note.id = p_id
            AND receipt_note_item.is_deleted = 0
            AND COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0) <> 0
    ) AS line
    WHERE (line.net_amt + line.vat_amt) <> 0
        AND gl_account_id('201-110') IS NOT NULL;

    SELECT purchase_order_id INTO v_po_id FROM receipt_note WHERE id = p_id LIMIT 1;
    IF v_po_id IS NOT NULL THEN
        CALL gl_post_purchase_order(v_po_id);
    END IF;
END$$
DELIMITER ;
