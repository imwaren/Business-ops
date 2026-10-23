DROP FUNCTION IF EXISTS gl_transaction_item_names$$
CREATE FUNCTION gl_transaction_item_names(p_transaction_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_names LONGTEXT DEFAULT NULL;
    DECLARE v_name TEXT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE names_cursor CURSOR FOR
        SELECT DISTINCT NULLIF(TRIM(i.name), '') AS item_name
        FROM booking_medicine bm INNER JOIN booking b ON b.id = bm.booking_id
        INNER JOIN {MASTER}.item i ON i.id = bm.medicine_id
        WHERE b.transaction_id = p_transaction_id AND b.is_deleted = 0
            AND bm.is_deleted = 0 AND NULLIF(TRIM(i.name), '') IS NOT NULL
        ORDER BY item_name;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
    OPEN names_cursor;
    names_loop: LOOP
        FETCH names_cursor INTO v_name;
        IF v_done = 1 THEN LEAVE names_loop; END IF;
        SET v_names = CONCAT_WS(', ', v_names, v_name);
    END LOOP;
    CLOSE names_cursor;
    RETURN v_names;
END$$
DROP FUNCTION IF EXISTS gl_receipt_note_item_names$$
CREATE FUNCTION gl_receipt_note_item_names(p_receipt_note_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_names LONGTEXT DEFAULT NULL;
    DECLARE v_name TEXT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE names_cursor CURSOR FOR
        SELECT DISTINCT COALESCE(NULLIF(TRIM(i.name), ''), CONCAT('Item #', ri.item_id)) AS item_name
        FROM receipt_note_item ri LEFT JOIN {MASTER}.item i ON i.id = ri.item_id
        WHERE ri.receipt_note_id = p_receipt_note_id AND ri.is_deleted = 0
        ORDER BY item_name;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
    OPEN names_cursor;
    names_loop: LOOP
        FETCH names_cursor INTO v_name;
        IF v_done = 1 THEN LEAVE names_loop; END IF;
        SET v_names = CONCAT_WS(', ', v_names, v_name);
    END LOOP;
    CLOSE names_cursor;
    RETURN v_names;
END$$
DROP FUNCTION IF EXISTS gl_transaction_patient_context$$
CREATE FUNCTION gl_transaction_patient_context(p_transaction_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_transaction_no TEXT DEFAULT NULL;
    DECLARE v_patient_name LONGTEXT DEFAULT NULL;
    DECLARE v_context LONGTEXT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_transaction_no = NULL;
    IF IFNULL(p_transaction_id, 0) <= 0 THEN RETURN NULL; END IF;
    SELECT NULLIF(TRIM(CAST(t.transaction_no AS CHAR)), ''),
        gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix)
    INTO v_transaction_no, v_patient_name
    FROM `transaction` t LEFT JOIN patient p ON p.id = t.patient_id
    WHERE t.id = p_transaction_id LIMIT 1;
    IF v_transaction_no IS NOT NULL THEN
        SET v_context = CONCAT('transaction #', v_transaction_no,
            IF(v_patient_name IS NULL, '', CONCAT(' / Patient: ', v_patient_name)));
    END IF;
    RETURN v_context;
END$$
DROP FUNCTION IF EXISTS gl_transaction_context$$
CREATE FUNCTION gl_transaction_context(p_transaction_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_items LONGTEXT DEFAULT NULL;
    IF IFNULL(p_transaction_id, 0) <= 0 THEN RETURN NULL; END IF;
    SET v_items = gl_transaction_item_names(p_transaction_id);
    RETURN gl_remark_append(gl_transaction_patient_context(p_transaction_id),
        IF(v_items IS NULL, NULL, CONCAT('Items: ', v_items)));
END$$
DROP FUNCTION IF EXISTS gl_item_history_transaction_id$$
CREATE FUNCTION gl_item_history_transaction_id(p_item_history_id INT)
RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_transaction_id INT DEFAULT NULL;
    DECLARE v_count INT DEFAULT 0;
    SELECT COUNT(DISTINCT links.transaction_id), MIN(links.transaction_id)
    INTO v_count, v_transaction_id FROM (
        SELECT b.transaction_id FROM item_history ih
        INNER JOIN booking_details bd ON bd.id = ih.activity_id
        INNER JOIN booking b ON b.id = bd.booking_id
        WHERE ih.id = p_item_history_id AND ih.activity IN ('perform', 'Machine Run', 'lab supplies', 'pharmacy')
        UNION
        SELECT b.transaction_id FROM item_history ih
        INNER JOIN booking_package_details bpd ON bpd.id = ih.activity_id
        INNER JOIN booking_details bd ON bd.id = bpd.booking_detail_id
        INNER JOIN booking b ON b.id = bd.booking_id
        WHERE ih.id = p_item_history_id AND ih.activity IN ('perform', 'Machine Run', 'lab supplies', 'pharmacy')
        UNION
        SELECT b.transaction_id FROM item_history ih
        INNER JOIN booking_medicine bm ON bm.id = ih.activity_id AND bm.medicine_id = ih.item_id
        INNER JOIN booking b ON b.id = bm.booking_id
        WHERE ih.id = p_item_history_id AND ih.activity = 'pharmacy'
    ) links WHERE links.transaction_id IS NOT NULL;
    RETURN IF(v_count = 1, v_transaction_id, NULL);
END$$
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
        SELECT transaction_id INTO v_transaction_id FROM {MASTER}.refund
        WHERE id = p_source_id AND branch_id = gl_branch_id() LIMIT 1;
    ELSEIF p_source = 'non_cash_deposit' THEN
        SELECT transaction_id INTO v_transaction_id FROM non_cash_deposit WHERE id = p_source_id LIMIT 1;
    ELSEIF p_source = 'item_history' THEN
        SELECT NULLIF(TRIM(i.name), '') INTO v_item_name
        FROM item_history ih LEFT JOIN {MASTER}.item i ON i.id = ih.item_id
        WHERE ih.id = p_source_id LIMIT 1;
        SET v_transaction_id = gl_item_history_transaction_id(p_source_id);
    ELSEIF p_source = 'receipt_note_item' THEN
        SELECT NULLIF(TRIM(i.name), ''), ri.receipt_note_id INTO v_item_name, v_receipt_note_id
        FROM receipt_note_item ri LEFT JOIN {MASTER}.item i ON i.id = ri.item_id
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
            FROM {MASTER}.refund r
            LEFT JOIN `transaction` t ON t.id = r.transaction_id
            LEFT JOIN {MASTER}.patient p ON p.id = r.patient_id AND p.is_deleted = 0
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
