-- Future receipt postings only. No data, triggers, schema or history are changed.
DELIMITER $$
DROP PROCEDURE IF EXISTS assert_receipt_note_vat_install$$
CREATE PROCEDURE assert_receipt_note_vat_install()
BEGIN
 IF DATABASE() NOT IN ('mytemr_master_uat') THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Wrong receipt VAT target environment/path';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ('gl_account_id','gl_billing_transaction_no','gl_patient_display_name','gl_rn_net','gl_rn_vat_amt','gl_rn_vat_type','gl_shared_clear','gl_shared_je','gl_shared_post_purchase_order')) <> 9 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Missing receipt VAT helper dependencies; inspect installed accounting path';
 END IF;
 IF (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME IN ('gl_master_apv_context','gl_shared_post_receipt_note')) <> 2 THEN
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
DROP PROCEDURE IF EXISTS gl_shared_post_receipt_note$$
CREATE PROCEDURE gl_shared_post_receipt_note(IN p_id INT)
proc: BEGIN
    DECLARE v_po_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM receipt_note_item WHERE receipt_note_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF NOT EXISTS (SELECT 1 FROM receipt_note s JOIN branch b ON b.id=s.branch_id WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;
    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_shared_clear('receipt_note_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0,
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
        LEFT JOIN item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
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
    SELECT 0,
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
        LEFT JOIN item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
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
    SELECT 0,
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
        LEFT JOIN item AS item ON item.id = receipt_note_item.item_id AND item.is_deleted = 0
        WHERE receipt_note.id = p_id
            AND receipt_note_item.is_deleted = 0
            AND COALESCE(receipt_note_item.amount * COALESCE(receipt_note_item.qty, 0), receipt_note_item.total, 0) <> 0
    ) AS line
    WHERE (line.net_amt + line.vat_amt) <> 0
        AND gl_account_id('201-110') IS NOT NULL;

    SELECT purchase_order_id INTO v_po_id FROM receipt_note WHERE id = p_id LIMIT 1;
    IF v_po_id IS NOT NULL THEN
        CALL gl_shared_post_purchase_order(v_po_id);
    END IF;
    BEGIN
        DECLARE done INT DEFAULT 0;
        DECLARE cid INT;
        DECLARE c CURSOR FOR SELECT id FROM receipt_note_item WHERE receipt_note_id=p_id;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
        OPEN c;
        journals: LOOP
            FETCH c INTO cid;
            IF done=1 THEN LEAVE journals; END IF;
            CALL gl_shared_je('receipt_note_item',cid);
        END LOOP;
        CLOSE c;
    END;
END$$
DELIMITER ;
