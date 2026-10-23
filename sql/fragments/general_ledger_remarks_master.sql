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
DROP FUNCTION IF EXISTS gl_branch_transaction_item_names$$
CREATE FUNCTION gl_branch_transaction_item_names(p_branch_id INT, p_transaction_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_names LONGTEXT DEFAULT NULL;
    DECLARE v_name TEXT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE names_cursor CURSOR FOR
        SELECT DISTINCT NULLIF(TRIM(i.name), '') AS item_name
        FROM (        SELECT bm.medicine_id FROM mytemr_diagnostic_mobile.booking_medicine bm
        INNER JOIN mytemr_diagnostic_mobile.booking b ON b.id = bm.booking_id
        WHERE p_branch_id = 1 AND b.transaction_id = p_transaction_id
            AND b.is_deleted = 0 AND bm.is_deleted = 0
        UNION ALL
        SELECT bm.medicine_id FROM mytemr_diagnostic.booking_medicine bm
        INNER JOIN mytemr_diagnostic.booking b ON b.id = bm.booking_id
        WHERE p_branch_id = 2 AND b.transaction_id = p_transaction_id
            AND b.is_deleted = 0 AND bm.is_deleted = 0
        UNION ALL
        SELECT bm.medicine_id FROM mytemr_diagnostic_mactan.booking_medicine bm
        INNER JOIN mytemr_diagnostic_mactan.booking b ON b.id = bm.booking_id
        WHERE p_branch_id = 3 AND b.transaction_id = p_transaction_id
            AND b.is_deleted = 0 AND bm.is_deleted = 0
        UNION ALL
        SELECT bm.medicine_id FROM mytemr_diagnostic_conso.booking_medicine bm
        INNER JOIN mytemr_diagnostic_conso.booking b ON b.id = bm.booking_id
        WHERE p_branch_id = 4 AND b.transaction_id = p_transaction_id
            AND b.is_deleted = 0 AND bm.is_deleted = 0
        UNION ALL
        SELECT bm.medicine_id FROM mytemr_diagnostic_mantlewood.booking_medicine bm
        INNER JOIN mytemr_diagnostic_mantlewood.booking b ON b.id = bm.booking_id
        WHERE p_branch_id = 7 AND b.transaction_id = p_transaction_id
            AND b.is_deleted = 0 AND bm.is_deleted = 0) medicines INNER JOIN item i ON i.id = medicines.medicine_id
        WHERE NULLIF(TRIM(i.name), '') IS NOT NULL ORDER BY item_name;
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
DROP FUNCTION IF EXISTS gl_branch_transaction_context$$
CREATE FUNCTION gl_branch_transaction_context(p_branch_id INT, p_transaction_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_transaction_no TEXT DEFAULT NULL;
    DECLARE v_patient_name LONGTEXT DEFAULT NULL;
    DECLARE v_context LONGTEXT DEFAULT NULL;
    DECLARE v_items LONGTEXT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_transaction_no = NULL;
    IF IFNULL(p_transaction_id, 0) <= 0 THEN RETURN NULL; END IF;
    CASE p_branch_id
        WHEN 1 THEN
            SELECT NULLIF(TRIM(CAST(t.transaction_no AS CHAR)), ''),
                gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix)
            INTO v_transaction_no, v_patient_name
            FROM mytemr_diagnostic_mobile.`transaction` t LEFT JOIN mytemr_diagnostic_mobile.patient p ON p.id = t.patient_id
            WHERE t.id = p_transaction_id LIMIT 1;
        WHEN 2 THEN
            SELECT NULLIF(TRIM(CAST(t.transaction_no AS CHAR)), ''),
                gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix)
            INTO v_transaction_no, v_patient_name
            FROM mytemr_diagnostic.`transaction` t LEFT JOIN mytemr_diagnostic.patient p ON p.id = t.patient_id
            WHERE t.id = p_transaction_id LIMIT 1;
        WHEN 3 THEN
            SELECT NULLIF(TRIM(CAST(t.transaction_no AS CHAR)), ''),
                gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix)
            INTO v_transaction_no, v_patient_name
            FROM mytemr_diagnostic_mactan.`transaction` t LEFT JOIN mytemr_diagnostic_mactan.patient p ON p.id = t.patient_id
            WHERE t.id = p_transaction_id LIMIT 1;
        WHEN 4 THEN
            SELECT NULLIF(TRIM(CAST(t.transaction_no AS CHAR)), ''),
                gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix)
            INTO v_transaction_no, v_patient_name
            FROM mytemr_diagnostic_conso.`transaction` t LEFT JOIN mytemr_diagnostic_conso.patient p ON p.id = t.patient_id
            WHERE t.id = p_transaction_id LIMIT 1;
        WHEN 7 THEN
            SELECT NULLIF(TRIM(CAST(t.transaction_no AS CHAR)), ''),
                gl_patient_display_name(p.last_name, p.first_name, p.middle_name, p.suffix)
            INTO v_transaction_no, v_patient_name
            FROM mytemr_diagnostic_mantlewood.`transaction` t LEFT JOIN mytemr_diagnostic_mantlewood.patient p ON p.id = t.patient_id
            WHERE t.id = p_transaction_id LIMIT 1;
        ELSE RETURN NULL;
    END CASE;
    IF v_transaction_no IS NOT NULL THEN
        SET v_context = CONCAT('branch #', p_branch_id, ' / transaction #', v_transaction_no,
            IF(v_patient_name IS NULL, '', CONCAT(' / Patient: ', v_patient_name)));
    END IF;
    SET v_items = gl_branch_transaction_item_names(p_branch_id, p_transaction_id);
    RETURN gl_remark_append(v_context, IF(v_items IS NULL, NULL, CONCAT('Items: ', v_items)));
END$$
DROP FUNCTION IF EXISTS gl_billing_context$$
CREATE FUNCTION gl_billing_context(p_billing_id INT, p_branch_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_context LONGTEXT DEFAULT NULL;
    DECLARE v_branch_id INT;
    DECLARE v_transaction_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE references_cursor CURSOR FOR
        SELECT DISTINCT be.branch_id, be.transaction_id FROM billing_entry be
        WHERE be.billing_id = p_billing_id AND be.is_deleted = 0 AND IFNULL(be.transaction_id, 0) > 0
            AND (p_branch_id IS NULL OR be.branch_id = p_branch_id)
        ORDER BY be.branch_id, be.transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
    OPEN references_cursor;
    references_loop: LOOP
        FETCH references_cursor INTO v_branch_id, v_transaction_id;
        IF v_done = 1 THEN LEAVE references_loop; END IF;
        SET v_context = gl_remark_append(v_context, gl_branch_transaction_context(v_branch_id, v_transaction_id));
    END LOOP;
    CLOSE references_cursor;
    RETURN v_context;
END$$
DROP FUNCTION IF EXISTS gl_collection_context$$
CREATE FUNCTION gl_collection_context(p_payment_id INT, p_branch_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_context LONGTEXT DEFAULT NULL;
    DECLARE v_branch_id INT;
    DECLARE v_transaction_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE references_cursor CURSOR FOR
        SELECT DISTINCT be.branch_id, be.transaction_id FROM billing_entry be INNER JOIN billing_payment_entry bpe ON bpe.billing_entry_id = be.id AND bpe.is_deleted = 0
        WHERE bpe.billing_payment_id = p_payment_id AND be.is_deleted = 0 AND IFNULL(be.transaction_id, 0) > 0
            AND (p_branch_id IS NULL OR be.branch_id = p_branch_id)
        ORDER BY be.branch_id, be.transaction_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;
    OPEN references_cursor;
    references_loop: LOOP
        FETCH references_cursor INTO v_branch_id, v_transaction_id;
        IF v_done = 1 THEN LEAVE references_loop; END IF;
        SET v_context = gl_remark_append(v_context, gl_branch_transaction_context(v_branch_id, v_transaction_id));
    END LOOP;
    CLOSE references_cursor;
    RETURN v_context;
END$$
DROP FUNCTION IF EXISTS gl_remark_context$$
CREATE FUNCTION gl_remark_context(p_source VARCHAR(250), p_source_id INT, p_branch_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_branch_id INT DEFAULT NULL;
    DECLARE v_transaction_id INT DEFAULT NULL;
    DECLARE v_payment_id INT DEFAULT NULL;
    DECLARE v_billing_id INT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_transaction_id = NULL;
    IF p_source = 'billing_entry' THEN
        SELECT branch_id, transaction_id INTO v_branch_id, v_transaction_id FROM billing_entry WHERE id = p_source_id LIMIT 1;
        RETURN gl_branch_transaction_context(v_branch_id, v_transaction_id);
    ELSEIF p_source = 'billing_payment' THEN
        RETURN gl_collection_context(p_source_id, p_branch_id);
    ELSEIF p_source = 'billing_payment_deposit' THEN
        SELECT billing_payment_id INTO v_payment_id FROM billing_payment_deposit WHERE id = p_source_id LIMIT 1;
        RETURN gl_collection_context(v_payment_id, p_branch_id);
    ELSEIF p_source = 'check_deposit' THEN
        SELECT billing_payment_id INTO v_payment_id FROM check_deposit WHERE id = p_source_id LIMIT 1;
        RETURN gl_collection_context(v_payment_id, p_branch_id);
    ELSEIF p_source = 'credit_memo' THEN
        SELECT billing_id INTO v_billing_id FROM credit_memo WHERE id = p_source_id LIMIT 1;
        RETURN gl_billing_context(v_billing_id, p_branch_id);
    ELSEIF p_source = 'debit_memo' THEN
        SELECT billing_id INTO v_billing_id FROM debit_memo WHERE id = p_source_id LIMIT 1;
        RETURN gl_billing_context(v_billing_id, p_branch_id);
    END IF;
    RETURN NULL;
END$$
DROP FUNCTION IF EXISTS gl_enrich_remark$$
CREATE FUNCTION gl_enrich_remark(p_source VARCHAR(250), p_source_id INT, p_branch_id INT, p_base LONGTEXT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_context LONGTEXT DEFAULT NULL;
    SET v_context = gl_remark_context(p_source, p_source_id, p_branch_id);
    -- Existing item-bearing receipt remarks are already complete; keep their prose.
    IF LEFT(v_context, 7) = 'Items: ' AND LOCATE(SUBSTRING(v_context, 8), IFNULL(p_base, '')) > 0 THEN
        RETURN p_base;
    END IF;
    RETURN gl_remark_append(p_base, v_context);
END$$
