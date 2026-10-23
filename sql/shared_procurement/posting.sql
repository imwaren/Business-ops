-- Shared procurement only: master accounting at 0. No backfill.
DELIMITER $$
DROP FUNCTION IF EXISTS gl_rn_vat_type$$
CREATE FUNCTION gl_rn_vat_type(p_line VARCHAR(100), p_item VARCHAR(100))
RETURNS VARCHAR(100)
DETERMINISTIC
NO SQL
BEGIN
    RETURN LOWER(TRIM(COALESCE(NULLIF(p_line, ''), NULLIF(p_item, ''), '')));
END$$
DROP FUNCTION IF EXISTS gl_rn_net$$
CREATE FUNCTION gl_rn_net(p_amount DECIMAL(13,4), p_vat DECIMAL(13,4), p_type VARCHAR(100))
RETURNS DECIMAL(13,4)
DETERMINISTIC
NO SQL
BEGIN
    IF IFNULL(p_amount, 0) = 0 THEN
        RETURN 0;
    END IF;
    IF p_type = 'vat_in' THEN
        IF p_vat IS NOT NULL THEN
            RETURN p_amount - p_vat;
        END IF;
        RETURN ROUND(p_amount / 1.12, 4);
    END IF;
    RETURN p_amount;
END$$
DROP FUNCTION IF EXISTS gl_rn_vat_amt$$
CREATE FUNCTION gl_rn_vat_amt(p_amount DECIMAL(13,4), p_vat DECIMAL(13,4), p_type VARCHAR(100))
RETURNS DECIMAL(13,4)
DETERMINISTIC
NO SQL
BEGIN
    IF p_type IN ('nvat', 'exempt', 'vat_exempt', 'zero_rated', 'zero-rated', '0rated') THEN
        RETURN 0;
    END IF;
    IF p_type = 'vat_in' THEN
        IF p_vat IS NOT NULL THEN
            RETURN p_vat;
        END IF;
        RETURN ROUND(p_amount - (p_amount / 1.12), 4);
    END IF;
    IF p_vat IS NOT NULL THEN
        RETURN p_vat;
    END IF;
    IF IFNULL(p_type, '') = '' THEN
        RETURN 0;
    END IF;
    RETURN ROUND(p_amount * 0.12, 4);
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_purchase_request$$
CREATE PROCEDURE gl_shared_post_purchase_request(IN p_id INT)
proc: BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM purchase_request_item WHERE purchase_request_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF NOT EXISTS (SELECT 1 FROM purchase_request s JOIN branch b ON b.id=s.branch_id WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;
    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_shared_clear('purchase_request_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;

END$$
DROP PROCEDURE IF EXISTS gl_shared_post_purchase_order$$
CREATE PROCEDURE gl_shared_post_purchase_order(IN p_id INT)
proc: BEGIN
    DECLARE v_pr_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM purchase_order_item WHERE purchase_order_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    IF NOT EXISTS (SELECT 1 FROM purchase_order s JOIN branch b ON b.id=s.branch_id WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;
    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_shared_clear('purchase_order_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;


    SELECT purchase_request_id INTO v_pr_id FROM purchase_order WHERE id = p_id LIMIT 1;
    IF v_pr_id IS NOT NULL THEN
        CALL gl_shared_post_purchase_request(v_pr_id);
    END IF;
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
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_ai$$
CREATE TRIGGER trg_gl_shared_purchase_request_ai AFTER INSERT ON purchase_request
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_au$$
CREATE TRIGGER trg_gl_shared_purchase_request_au AFTER UPDATE ON purchase_request
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_ai$$
CREATE TRIGGER trg_gl_shared_purchase_order_ai AFTER INSERT ON purchase_order
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_au$$
CREATE TRIGGER trg_gl_shared_purchase_order_au AFTER UPDATE ON purchase_order
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_ai$$
CREATE TRIGGER trg_gl_shared_receipt_note_ai AFTER INSERT ON receipt_note
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_au$$
CREATE TRIGGER trg_gl_shared_receipt_note_au AFTER UPDATE ON receipt_note
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_item_ai$$
CREATE TRIGGER trg_gl_shared_purchase_request_item_ai AFTER INSERT ON purchase_request_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.purchase_request_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_item_au$$
CREATE TRIGGER trg_gl_shared_purchase_request_item_au AFTER UPDATE ON purchase_request_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_request(NEW.purchase_request_id); IF OLD.purchase_request_id<>NEW.purchase_request_id THEN CALL gl_shared_post_purchase_request(OLD.purchase_request_id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_request_item_ad$$
CREATE TRIGGER trg_gl_shared_purchase_request_item_ad AFTER DELETE ON purchase_request_item
FOR EACH ROW BEGIN CALL gl_shared_clear('purchase_request_item',OLD.id); CALL gl_shared_post_purchase_request(OLD.purchase_request_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_item_ai$$
CREATE TRIGGER trg_gl_shared_purchase_order_item_ai AFTER INSERT ON purchase_order_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.purchase_order_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_item_au$$
CREATE TRIGGER trg_gl_shared_purchase_order_item_au AFTER UPDATE ON purchase_order_item
FOR EACH ROW BEGIN CALL gl_shared_post_purchase_order(NEW.purchase_order_id); IF OLD.purchase_order_id<>NEW.purchase_order_id THEN CALL gl_shared_post_purchase_order(OLD.purchase_order_id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_shared_purchase_order_item_ad$$
CREATE TRIGGER trg_gl_shared_purchase_order_item_ad AFTER DELETE ON purchase_order_item
FOR EACH ROW BEGIN CALL gl_shared_clear('purchase_order_item',OLD.id); CALL gl_shared_post_purchase_order(OLD.purchase_order_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_item_ai$$
CREATE TRIGGER trg_gl_shared_receipt_note_item_ai AFTER INSERT ON receipt_note_item
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.receipt_note_id); END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_item_au$$
CREATE TRIGGER trg_gl_shared_receipt_note_item_au AFTER UPDATE ON receipt_note_item
FOR EACH ROW BEGIN CALL gl_shared_post_receipt_note(NEW.receipt_note_id); IF OLD.receipt_note_id<>NEW.receipt_note_id THEN CALL gl_shared_post_receipt_note(OLD.receipt_note_id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_shared_receipt_note_item_ad$$
CREATE TRIGGER trg_gl_shared_receipt_note_item_ad AFTER DELETE ON receipt_note_item
FOR EACH ROW BEGIN CALL gl_shared_clear('receipt_note_item',OLD.id); CALL gl_shared_post_receipt_note(OLD.receipt_note_id); END$$
DELIMITER ;
