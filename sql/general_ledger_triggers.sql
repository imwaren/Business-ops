-- Posted general_ledger helpers and triggers for EACH clinic DB
-- (mobile, oakridge, mactan, consolacion, manticwood).
-- Billing / collection / deposit triggers live in sql/general_ledger_triggers_master.sql
-- and must be applied on mytemr_master only.
-- Render with scripts/prepare_gl_remarks_sql.py before any installation.
-- Then: INSERT IGNORE INTO gl_settings (branch_id) VALUES (<1|2|3|4|7>);
-- Installation changes future postings only; do not run a backfill for remark enrichment.
-- After ALTERs in sql/migrations/journal_entry_source.sql, recreate this file
-- so GL inserts write system journal_entry. CSR sources
-- (payment_multiple_method, payment, transaction, sales_return) share one JE per
-- transaction; other sources stay one JE per (source, source_id).
-- Cycle map (Action / Description / Debit / Credit):
--   sql/general_ledger_accounting_cycle_map_v3_client.csv
--   sql/general_ledger_accounting_cycle_map_v3_dev.csv

-- Keep stored routine literals compatible with the source schema and helper results.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Validate prepared SQL before any installation DDL. No key values are logged.
DELIMITER $$
DROP PROCEDURE IF EXISTS gl_assert_remark_key$$
CREATE PROCEDURE gl_assert_remark_key()
BEGIN
    IF '__GL_ENCRYPTION_KEY_HEX__' NOT REGEXP '^[0-9a-fA-F]+$'
        OR MOD(CHAR_LENGTH('__GL_ENCRYPTION_KEY_HEX__'), 2) <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Prepare GL SQL with scripts/prepare_gl_remarks_sql.py first';
    END IF;
END$$
CALL gl_assert_remark_key()$$
DROP PROCEDURE gl_assert_remark_key$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS gl_settings (
    branch_id int(11) NOT NULL,
    PRIMARY KEY (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP INDEX IF EXISTS idx_gl_source_id ON general_ledger;
CREATE INDEX idx_gl_source_id ON general_ledger (source, source_id, is_deleted);

DROP INDEX IF EXISTS idx_gl_date_account ON general_ledger;
CREATE INDEX idx_gl_date_account ON general_ledger (date, is_deleted, account_type_id);

ALTER TABLE non_cash_deposit MODIFY id INT(11) NOT NULL AUTO_INCREMENT;

-- Prepare this file with scripts/prepare_gl_remarks_sql.py before installation.
-- Run in each affected diagnostic/master schema before installing remark helpers.
-- Repeating this migration is safe. It does not rewrite remarks or accounting rows.
ALTER TABLE journal_entry MODIFY COLUMN remarks LONGTEXT DEFAULT NULL;
ALTER TABLE journal_entry_item MODIFY COLUMN remarks LONGTEXT DEFAULT NULL;

DELIMITER $$

DROP FUNCTION IF EXISTS gl_account_id$$

CREATE FUNCTION gl_account_id(p_code VARCHAR(255))
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_id INT DEFAULT NULL;
    SELECT account_type.id INTO v_id
    FROM mytemr_master.account_type AS account_type
    WHERE account_type.code = p_code
        AND account_type.parent_id IS NULL
        AND account_type.is_deleted = 0
    LIMIT 1;
    RETURN v_id;
END$$

DROP FUNCTION IF EXISTS gl_branch_id$$

CREATE FUNCTION gl_branch_id()
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_id INT DEFAULT NULL;
    SELECT branch_id INTO v_id FROM gl_settings LIMIT 1;
    RETURN v_id;
END$$

DROP FUNCTION IF EXISTS gl_mop_code$$

CREATE FUNCTION gl_mop_code(p_type VARCHAR(100), p_other VARCHAR(100))
RETURNS VARCHAR(32)
DETERMINISTIC
NO SQL
BEGIN
    DECLARE v_type VARCHAR(100);
    DECLARE v_other VARCHAR(100);
    SET v_type = LOWER(IFNULL(p_type, ''));
    SET v_other = LOWER(REPLACE(IFNULL(p_other, ''), ' ', ''));
    IF v_type = 'cash' THEN
        RETURN '100-004';
    END IF;
    IF v_type = 'card' THEN
        RETURN '102-192';
    END IF;
    IF v_type = 'gcash' THEN
        RETURN '102-193';
    END IF;
    IF v_type = 'company_wallet' THEN
        RETURN '102-113B';
    END IF;
    IF v_type = 'giftcheck' THEN
        RETURN '102-193';
    END IF;
    IF v_type = 'others' AND v_other = 'ar' THEN
        RETURN '102-193';
    END IF;
    IF v_type = 'others' AND v_other = 'check' THEN
        RETURN '100-004';
    END IF;
    IF v_type = 'others' AND v_other IN ('gcash', 'maya', 'qrph', 'qr') THEN
        RETURN '102-193';
    END IF;
    RETURN NULL;
END$$

DROP FUNCTION IF EXISTS gl_is_refund_new_payment$$

CREATE FUNCTION gl_is_refund_new_payment(p_payment_id INT)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_cnt INT DEFAULT 0;
    IF p_payment_id IS NULL THEN
        RETURN 0;
    END IF;
    SELECT COUNT(*) INTO v_cnt
    FROM mytemr_master.refund
    WHERE refund.new_payment_id = p_payment_id
        AND refund.branch_id = gl_branch_id()
        AND refund.is_deleted = 0;
    RETURN IF(IFNULL(v_cnt, 0) > 0, 1, 0);
END$$

DROP FUNCTION IF EXISTS gl_pmm_net_amount$$

CREATE FUNCTION gl_pmm_net_amount(p_pmm_id INT)
RETURNS DECIMAL(13,4)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_type VARCHAR(250);
    DECLARE v_amount DECIMAL(13,4) DEFAULT 0;
    DECLARE v_pay_id INT;
    DECLARE v_change DECIMAL(13,4) DEFAULT 0;
    DECLARE v_first_cash_id INT DEFAULT NULL;

    IF IFNULL(p_pmm_id, 0) <= 0 THEN
        RETURN 0;
    END IF;

    SELECT pmm.type,
        IFNULL(pmm.amount, 0),
        pmm.payment_id,
        IFNULL(pay.change, 0)
    INTO v_type, v_amount, v_pay_id, v_change
    FROM payment_multiple_method AS pmm
    LEFT JOIN payment AS pay ON pay.id = pmm.payment_id
    WHERE pmm.id = p_pmm_id
    LIMIT 1;

    IF LOWER(IFNULL(v_type, '')) <> 'cash' THEN
        RETURN v_amount;
    END IF;

    SELECT MIN(cash_pmm.id)
    INTO v_first_cash_id
    FROM payment_multiple_method AS cash_pmm
    WHERE cash_pmm.payment_id = v_pay_id
        AND cash_pmm.is_deleted = 0
        AND LOWER(IFNULL(cash_pmm.type, '')) = 'cash';

    IF v_first_cash_id IS NULL OR v_first_cash_id <> p_pmm_id THEN
        RETURN v_amount;
    END IF;

    RETURN GREATEST(0, v_amount - IFNULL(v_change, 0));
END$$

DROP FUNCTION IF EXISTS gl_group_revenue_code$$

CREATE FUNCTION gl_group_revenue_code(p_group_id INT)
RETURNS VARCHAR(32)
DETERMINISTIC
NO SQL
BEGIN
    IF IFNULL(p_group_id, 0) IN (1, 2) THEN
        RETURN '400-010';
    END IF;
    RETURN '400-020';
END$$

DROP FUNCTION IF EXISTS gl_bd_group_id$$

CREATE FUNCTION gl_bd_group_id(p_booking_detail_id INT)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_group_id INT DEFAULT NULL;
    IF IFNULL(p_booking_detail_id, 0) <= 0 THEN
        RETURN NULL;
    END IF;
    SELECT CASE
        WHEN MAX(CASE WHEN groups.group_id = 1 THEN 1 ELSE 0 END) = 1 THEN 1
        WHEN MAX(CASE WHEN groups.group_id = 2 THEN 1 ELSE 0 END) = 1 THEN 2
        WHEN MAX(groups.has_test) = 1 THEN MAX(CASE WHEN groups.group_id = 3 THEN 3 ELSE NULL END)
        ELSE NULL
    END INTO v_group_id
    FROM (
        SELECT lab_test.group_id AS group_id,
            CASE WHEN lab_test.id IS NOT NULL THEN 1 ELSE 0 END AS has_test
        FROM booking_details
        LEFT JOIN lab_test ON lab_test.id = booking_details.test_id
            AND lab_test.is_deleted = 0
            AND LOWER(IFNULL(booking_details.type, '')) NOT IN ('package', 'wallet', 'company_wallet')
        WHERE booking_details.id = p_booking_detail_id
            AND booking_details.is_deleted = 0
        UNION ALL
        SELECT pkg_lab.group_id,
            1
        FROM booking_package_details AS bpd
        INNER JOIN lab_test AS pkg_lab ON pkg_lab.id = bpd.test_id
            AND pkg_lab.is_deleted = 0
        WHERE bpd.booking_detail_id = p_booking_detail_id
            AND bpd.is_deleted = 0
    ) AS groups;
    RETURN v_group_id;
END$$

DROP FUNCTION IF EXISTS gl_bd_is_third_party$$

CREATE FUNCTION gl_bd_is_third_party(p_booking_detail_id INT)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_hit INT DEFAULT 0;
    IF IFNULL(p_booking_detail_id, 0) <= 0 THEN
        RETURN 0;
    END IF;
    SELECT MAX(CASE
            WHEN UPPER(IFNULL(names.exam_name, '')) LIKE '%HOLTER%'
                OR UPPER(IFNULL(names.exam_name, '')) LIKE '%BP MONITORING%'
                OR UPPER(IFNULL(names.exam_name, '')) LIKE '%APB%'
            THEN 1 ELSE 0
        END) INTO v_hit
    FROM (
        SELECT lab_test.name AS exam_name
        FROM booking_details
        LEFT JOIN lab_test ON lab_test.id = booking_details.test_id
            AND lab_test.is_deleted = 0
            AND LOWER(IFNULL(booking_details.type, '')) NOT IN ('package', 'wallet', 'company_wallet')
        WHERE booking_details.id = p_booking_detail_id
            AND booking_details.is_deleted = 0
        UNION ALL
        SELECT pkg_lab.name
        FROM booking_package_details AS bpd
        INNER JOIN lab_test AS pkg_lab ON pkg_lab.id = bpd.test_id
            AND pkg_lab.is_deleted = 0
        WHERE bpd.booking_detail_id = p_booking_detail_id
            AND bpd.is_deleted = 0
    ) AS names;
    RETURN IFNULL(v_hit, 0);
END$$

DROP FUNCTION IF EXISTS gl_item_history_remarks$$
CREATE FUNCTION gl_item_history_remarks(p_ih_id INT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
READS SQL DATA
BEGIN
    DECLARE v_item_id INT DEFAULT NULL;
    DECLARE v_activity VARCHAR(255) DEFAULT NULL;
    DECLARE v_activity_id INT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_item_id = NULL;
    IF IFNULL(p_ih_id, 0) <= 0 THEN RETURN NULL; END IF;
    SELECT item_id, activity, activity_id INTO v_item_id, v_activity, v_activity_id FROM item_history WHERE id = p_ih_id LIMIT 1;
    RETURN gl_enrich_remark('item_history', p_ih_id, gl_branch_id(),
        CONCAT('item_history #', p_ih_id, ', item #', IFNULL(v_item_id, ''),
            IF(v_activity = 'Blindsheet', CONCAT(', blindsheet #', IFNULL(v_activity_id, '')), '')));
END$$

DROP FUNCTION IF EXISTS gl_line_revenue_code$$

CREATE FUNCTION gl_line_revenue_code(p_booking_detail_id INT)
RETURNS VARCHAR(32)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_code VARCHAR(32) DEFAULT '400-020';
    IF IFNULL(p_booking_detail_id, 0) <= 0 THEN
        RETURN '400-020';
    END IF;
    SELECT CASE
        WHEN IFNULL(booking_details.charge_to, 0) = 3210 THEN '400-060'
        WHEN lab_test.is_sendout = 1
            OR pkg_lab.is_sendout = 1
            OR booking_details.send_out_on IS NOT NULL THEN '400-050'
        WHEN gl_bd_is_third_party(booking_details.id) = 1 THEN '400-040'
        WHEN booking.transaction_type = 'consultation' THEN '400-030'
        WHEN UPPER(IFNULL(child_cat.name, '')) = 'CLINICA VAXX'
            OR booking.transaction_type = 'vaccination' THEN '480-030'
        WHEN UPPER(IFNULL(parent_cat.name, IFNULL(child_cat.name, ''))) = 'EYE CARE' THEN '400-900'
        WHEN booking.transaction_type = 'pharmacy' THEN '420-000'
        ELSE gl_group_revenue_code(gl_bd_group_id(booking_details.id))
    END INTO v_code
    FROM booking_details
    INNER JOIN booking ON booking.id = booking_details.booking_id
    LEFT JOIN lab_test ON lab_test.id = booking_details.test_id
        AND lab_test.is_deleted = 0
        AND LOWER(IFNULL(booking_details.type, '')) NOT IN ('package', 'wallet', 'company_wallet')
    LEFT JOIN booking_package_details AS bpd ON bpd.booking_detail_id = booking_details.id
        AND bpd.is_deleted = 0
    LEFT JOIN lab_test AS pkg_lab ON pkg_lab.id = bpd.test_id
        AND pkg_lab.is_deleted = 0
    LEFT JOIN category AS child_cat ON child_cat.id = COALESCE(lab_test.category_id, pkg_lab.category_id)
        AND child_cat.is_deleted = 0
    LEFT JOIN category AS parent_cat ON parent_cat.id = child_cat.category_id
        AND parent_cat.is_deleted = 0
    WHERE booking_details.id = p_booking_detail_id
        AND booking_details.is_deleted = 0
    LIMIT 1;
    RETURN IFNULL(v_code, '400-020');
END$$

DROP FUNCTION IF EXISTS gl_txn_group_revenue_code$$

CREATE FUNCTION gl_txn_group_revenue_code(p_transaction_id INT)
RETURNS VARCHAR(32)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_code VARCHAR(32) DEFAULT '400-020';
    IF IFNULL(p_transaction_id, 0) <= 0 THEN
        RETURN '400-020';
    END IF;
    SELECT CASE
        WHEN MAX(CASE WHEN gl_bd_group_id(bd.id) IN (1, 2) THEN 1 ELSE 0 END) = 1 THEN '400-010'
        ELSE '400-020'
    END INTO v_code
    FROM booking_details AS bd
    INNER JOIN booking AS b ON b.id = bd.booking_id
        AND b.is_deleted = 0
        AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
    WHERE bd.is_deleted = 0
        AND b.transaction_id = p_transaction_id
        AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
        AND IFNULL(bd.charge_to, 0) = 0
        AND gl_line_revenue_code(bd.id) IN ('400-010', '400-020');
    RETURN IFNULL(v_code, '400-020');
END$$

DROP FUNCTION IF EXISTS gl_revenue_code$$

CREATE FUNCTION gl_revenue_code(p_transaction_id INT)
RETURNS VARCHAR(32)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_code VARCHAR(32) DEFAULT '400-020';
    SELECT CASE
        WHEN MAX(CASE
            WHEN lab_test.is_sendout = 1
                OR pkg_lab.is_sendout = 1
                OR booking_details.send_out_on IS NOT NULL
            THEN 1 ELSE 0 END) = 1 THEN '400-050'
        WHEN MAX(CASE
            WHEN gl_bd_is_third_party(booking_details.id) = 1
            THEN 1 ELSE 0 END) = 1 THEN '400-040'
        WHEN MAX(CASE
            WHEN lab_test.is_yakap = 1
                OR lab_test.is_consulta = 1
                OR pkg_lab.is_yakap = 1
                OR pkg_lab.is_consulta = 1
                OR UPPER(IFNULL(parent_cat.name, IFNULL(child_cat.name, ''))) = 'YAKAP'
                OR UPPER(IFNULL(booking.remarks, '')) LIKE '%YAKAP%'
                OR LOWER(IFNULL(booking.transaction_type, '')) = 'yakap'
            THEN 1 ELSE 0 END) = 1 THEN '400-060'
        WHEN MAX(CASE WHEN booking.transaction_type = 'consultation' THEN 1 ELSE 0 END) = 1 THEN '400-030'
        WHEN MAX(CASE
            WHEN UPPER(IFNULL(child_cat.name, '')) = 'CLINICA VAXX'
                OR booking.transaction_type = 'vaccination'
            THEN 1 ELSE 0 END) = 1 THEN '480-030'
        WHEN MAX(CASE
            WHEN UPPER(IFNULL(parent_cat.name, IFNULL(child_cat.name, ''))) = 'EYE CARE'
            THEN 1 ELSE 0 END) = 1 THEN '400-900'
        WHEN MAX(CASE WHEN booking.transaction_type = 'pharmacy' THEN 1 ELSE 0 END) = 1 THEN '420-000'
        ELSE gl_txn_group_revenue_code(p_transaction_id)
    END INTO v_code
    FROM booking
    LEFT JOIN booking_details ON booking_details.booking_id = booking.id
        AND booking_details.is_deleted = 0
    LEFT JOIN lab_test ON lab_test.id = booking_details.test_id
        AND lab_test.is_deleted = 0
        AND LOWER(IFNULL(booking_details.type, '')) NOT IN ('package', 'wallet', 'company_wallet')
    LEFT JOIN booking_package_details AS bpd ON bpd.booking_detail_id = booking_details.id
        AND bpd.is_deleted = 0
    LEFT JOIN lab_test AS pkg_lab ON pkg_lab.id = bpd.test_id
        AND pkg_lab.is_deleted = 0
    LEFT JOIN category AS child_cat ON child_cat.id = COALESCE(lab_test.category_id, pkg_lab.category_id)
        AND child_cat.is_deleted = 0
    LEFT JOIN category AS parent_cat ON parent_cat.id = child_cat.category_id
        AND parent_cat.is_deleted = 0
    WHERE booking.is_deleted = 0
        AND booking.transaction_id = p_transaction_id;
    RETURN IFNULL(v_code, '400-020');
END$$

DROP FUNCTION IF EXISTS gl_billing_payer_label$$
DROP FUNCTION IF EXISTS gl_billing_mop_code$$

DROP FUNCTION IF EXISTS gl_csr_txn_id$$

CREATE FUNCTION gl_csr_txn_id(p_source VARCHAR(250), p_source_id INT)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_txn_id INT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_txn_id = NULL;

    IF IFNULL(p_source_id, 0) <= 0 THEN
        RETURN NULL;
    END IF;
    IF p_source IN ('transaction', 'sales_return') THEN
        RETURN p_source_id;
    END IF;
    IF p_source = 'payment' THEN
        SELECT payment.transaction_id INTO v_txn_id
        FROM payment
        WHERE payment.id = p_source_id
        LIMIT 1;
        RETURN v_txn_id;
    END IF;
    IF p_source = 'payment_multiple_method' THEN
        SELECT pay.transaction_id INTO v_txn_id
        FROM payment_multiple_method AS pmm
        INNER JOIN payment AS pay ON pay.id = pmm.payment_id
        WHERE pmm.id = p_source_id
        LIMIT 1;
        RETURN v_txn_id;
    END IF;
    RETURN NULL;
END$$

-- BEGIN SHARED REMARK HELPERS
-- Shared remark functions, embedded in each standalone trigger script.
-- Render the encryption-key token using scripts/prepare_gl_remarks_sql.py.
DROP FUNCTION IF EXISTS gl_remark_append$$
CREATE FUNCTION gl_remark_append(p_base LONGTEXT, p_context LONGTEXT)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
DETERMINISTIC NO SQL
BEGIN
    DECLARE v_context LONGTEXT;
    DECLARE v_reference TEXT;
    DECLARE v_boundary INT DEFAULT 0;
    IF p_context IS NULL OR TRIM(p_context) = '' THEN RETURN p_base; END IF;
    IF LOCATE(p_context, IFNULL(p_base, '')) > 0 THEN RETURN p_base; END IF;
    SET v_context = p_context;
    -- The reference was resolved by ID before formatting. This comparison
    -- deduplicates a generated label; it never resolves a patient from prose.
    IF LEFT(v_context, 13) = 'transaction #' THEN
        SET v_boundary = LOCATE(' / Patient: ', v_context);
        IF v_boundary = 0 THEN SET v_boundary = LOCATE(' | ', v_context); END IF;
        IF v_boundary > 0 THEN
            SET v_reference = LEFT(v_context, v_boundary - 1);
            IF RIGHT(IFNULL(p_base, ''), CHAR_LENGTH(v_reference)) = v_reference
                OR LOCATE(CONCAT(v_reference, ' /'), IFNULL(p_base, '')) > 0
                OR LOCATE(CONCAT(v_reference, ' |'), IFNULL(p_base, '')) > 0 THEN
                SET v_context = SUBSTRING(v_context, v_boundary + 3);
            END IF;
        END IF;
    END IF;
    IF LOCATE(v_context, IFNULL(p_base, '')) > 0 THEN RETURN p_base; END IF;
    RETURN CONCAT_WS(' | ', NULLIF(p_base, ''), v_context);
END$$

DROP FUNCTION IF EXISTS gl_patient_display_name$$
CREATE FUNCTION gl_patient_display_name(
    p_last VARBINARY(255), p_first VARBINARY(255),
    p_middle VARBINARY(255), p_suffix VARBINARY(50)
)
RETURNS LONGTEXT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
DETERMINISTIC NO SQL
BEGIN
    DECLARE v_last TEXT;
    DECLARE v_given TEXT;
    SET v_last = NULLIF(TRIM(CAST(AES_DECRYPT(p_last, UNHEX('__GL_ENCRYPTION_KEY_HEX__')) AS CHAR CHARSET utf8mb4)), '');
    SET v_given = NULLIF(TRIM(CONCAT_WS(' ',
        NULLIF(TRIM(CAST(AES_DECRYPT(p_first, UNHEX('__GL_ENCRYPTION_KEY_HEX__')) AS CHAR CHARSET utf8mb4)), ''),
        NULLIF(TRIM(CAST(AES_DECRYPT(p_middle, UNHEX('__GL_ENCRYPTION_KEY_HEX__')) AS CHAR CHARSET utf8mb4)), ''),
        NULLIF(TRIM(CAST(AES_DECRYPT(p_suffix, UNHEX('__GL_ENCRYPTION_KEY_HEX__')) AS CHAR CHARSET utf8mb4)), '')
    )), '');
    RETURN NULLIF(CONCAT_WS(', ', v_last, v_given), '');
END$$
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
        INNER JOIN mytemr_master.item i ON i.id = bm.medicine_id
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
        FROM receipt_note_item ri LEFT JOIN mytemr_master.item i ON i.id = ri.item_id
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
-- END SHARED REMARK HELPERS

DROP PROCEDURE IF EXISTS gl_rebuild_txn_je$$

CREATE PROCEDURE gl_rebuild_txn_je(IN p_txn_id INT)
proc: BEGIN
    DECLARE v_je_id INT DEFAULT NULL;
    DECLARE v_migrated INT DEFAULT 0;
    DECLARE v_txn_no VARCHAR(250);
    DECLARE v_date DATE;
    DECLARE v_added_by INT;
    DECLARE v_cnt INT DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_je_id = NULL;

    IF IFNULL(p_txn_id, 0) <= 0 THEN
        LEAVE proc;
    END IF;

    SELECT COALESCE(NULLIF(txn.transaction_no, ''), CAST(txn.id AS CHAR)),
        DATE(txn.transaction_date),
        IFNULL(txn.added_by, 0)
    INTO v_txn_no, v_date, v_added_by
    FROM `transaction` AS txn
    WHERE txn.id = p_txn_id
    LIMIT 1;

    IF v_date IS NULL THEN
        LEAVE proc;
    END IF;

    SELECT journal_entry.id, IFNULL(journal_entry.is_migrated, 0)
    INTO v_je_id, v_migrated
    FROM journal_entry
    WHERE journal_entry.source = 'transaction'
        AND journal_entry.source_id = p_txn_id
        AND journal_entry.is_deleted = 0
    LIMIT 1;

    IF v_je_id IS NOT NULL AND IFNULL(v_migrated, 0) = 1 THEN
        LEAVE proc;
    END IF;

    SELECT COUNT(*)
    INTO v_cnt
    FROM general_ledger AS gl
    WHERE gl.is_deleted = 0
        AND (
            (gl.source = 'transaction' AND gl.source_id = p_txn_id)
            OR (
                gl.source = 'payment'
                AND gl.source_id IN (
                    SELECT payment.id FROM payment WHERE payment.transaction_id = p_txn_id
                )
            )
            OR (
                gl.source = 'payment_multiple_method'
                AND gl.source_id IN (
                    SELECT pmm.id
                    FROM payment_multiple_method AS pmm
                    INNER JOIN payment AS pay ON pay.id = pmm.payment_id
                    WHERE pay.transaction_id = p_txn_id
                )
            )
            OR (gl.source = 'sales_return' AND gl.source_id = p_txn_id)
        );

    IF IFNULL(v_cnt, 0) = 0 THEN
        IF v_je_id IS NOT NULL THEN
            UPDATE journal_entry_item
            SET is_deleted = 1,
                updated_on = NOW()
            WHERE journal_entry_id = v_je_id
                AND is_deleted = 0;
            UPDATE journal_entry
            SET is_deleted = 1,
                updated_on = NOW()
            WHERE id = v_je_id;
        END IF;
        LEAVE proc;
    END IF;

    IF v_je_id IS NULL THEN
        INSERT INTO journal_entry (
            cost_center_id, date, type, remarks, status,
            total_debit, total_credit, source, source_id,
            added_by, added_on, is_deleted
        ) VALUES (
            gl_branch_id(),
            v_date,
            'transaction',
            gl_enrich_remark('transaction', p_txn_id, gl_branch_id(), CONCAT('transaction #', v_txn_no)),
            'approved',
            0,
            0,
            'transaction',
            p_txn_id,
            v_added_by,
            NOW(),
            0
        );
        SET v_je_id = LAST_INSERT_ID();
    ELSE
        UPDATE journal_entry
        SET date = v_date,
            type = 'transaction',
            remarks = gl_enrich_remark('transaction', p_txn_id, gl_branch_id(), CONCAT('transaction #', v_txn_no)),
            updated_on = NOW()
        WHERE id = v_je_id;
    END IF;

    UPDATE journal_entry_item
    SET is_deleted = 1,
        updated_on = NOW()
    WHERE journal_entry_id = v_je_id
        AND is_deleted = 0;

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
    WHERE gl.is_deleted = 0
        AND gl.account_type_id IS NOT NULL
        AND (
            (gl.source = 'transaction' AND gl.source_id = p_txn_id)
            OR (
                gl.source = 'payment'
                AND gl.source_id IN (
                    SELECT payment.id FROM payment WHERE payment.transaction_id = p_txn_id
                )
            )
            OR (
                gl.source = 'payment_multiple_method'
                AND gl.source_id IN (
                    SELECT pmm.id
                    FROM payment_multiple_method AS pmm
                    INNER JOIN payment AS pay ON pay.id = pmm.payment_id
                    WHERE pay.transaction_id = p_txn_id
                )
            )
            OR (gl.source = 'sales_return' AND gl.source_id = p_txn_id)
        )
    ORDER BY gl.id;

    UPDATE journal_entry
    SET total_debit = IFNULL((
            SELECT SUM(journal_entry_item.debit)
            FROM journal_entry_item
            WHERE journal_entry_item.journal_entry_id = v_je_id
                AND journal_entry_item.is_deleted = 0
        ), 0),
        total_credit = IFNULL((
            SELECT SUM(journal_entry_item.credit)
            FROM journal_entry_item
            WHERE journal_entry_item.journal_entry_id = v_je_id
                AND journal_entry_item.is_deleted = 0
        ), 0),
        updated_on = NOW()
    WHERE id = v_je_id;
END$$

DROP PROCEDURE IF EXISTS gl_clear$$

CREATE PROCEDURE gl_clear(IN p_source VARCHAR(250), IN p_source_id INT)
BEGIN
    DECLARE v_txn_id INT DEFAULT NULL;

    UPDATE general_ledger
    SET is_deleted = 1,
        updated_on = NOW()
    WHERE source = p_source
        AND source_id = p_source_id
        AND is_deleted = 0;

    IF p_source IN ('payment_multiple_method', 'payment', 'transaction', 'sales_return') THEN
        SET v_txn_id = gl_csr_txn_id(p_source, p_source_id);
        IF v_txn_id IS NOT NULL THEN
            CALL gl_rebuild_txn_je(v_txn_id);
        END IF;
    ELSEIF p_source <> 'journal_entry' THEN
        UPDATE journal_entry_item
        INNER JOIN journal_entry
            ON journal_entry.id = journal_entry_item.journal_entry_id
            AND journal_entry.source = p_source
            AND journal_entry.source_id = p_source_id
            AND journal_entry.is_deleted = 0
        SET journal_entry_item.is_deleted = 1,
            journal_entry_item.updated_on = NOW()
        WHERE journal_entry_item.is_deleted = 0;

        UPDATE journal_entry
        SET is_deleted = 1,
            updated_on = NOW()
        WHERE source = p_source
            AND source_id = p_source_id
            AND is_deleted = 0;
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_insert$$

CREATE PROCEDURE gl_insert(
    IN p_source VARCHAR(250),
    IN p_source_id INT,
    IN p_account_type_id INT,
    IN p_date DATE,
    IN p_debit DECIMAL(13,4),
    IN p_credit DECIMAL(13,4),
    IN p_remark LONGTEXT,
    IN p_added_by INT
)
BEGIN
    IF p_account_type_id IS NOT NULL
        AND p_date IS NOT NULL
        AND (IFNULL(p_debit, 0) <> 0 OR IFNULL(p_credit, 0) <> 0)
    THEN
        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        ) VALUES (
            gl_branch_id(), p_source, p_source_id, p_account_type_id, p_date,
            IFNULL(p_debit, 0), IFNULL(p_credit, 0), p_remark, p_added_by, NOW(), 0
        );
    END IF;
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
    IN p_added_by INT
)
BEGIN
    DECLARE v_je_id INT DEFAULT NULL;
    DECLARE v_txn_id INT DEFAULT NULL;
    DECLARE v_header_remark LONGTEXT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_je_id = NULL;

    IF IFNULL(p_source, '') <> '' AND p_source <> 'journal_entry' THEN
        IF p_source IN ('payment_multiple_method', 'payment', 'transaction', 'sales_return') THEN
            SET v_txn_id = gl_csr_txn_id(p_source, p_source_id);
            IF v_txn_id IS NOT NULL THEN
                CALL gl_rebuild_txn_je(v_txn_id);
            END IF;
        ELSE
            SET v_header_remark = gl_enrich_remark(p_source, p_source_id, gl_branch_id(),
                CASE
                        WHEN p_source = 'refund' THEN CONCAT(
                            'refund #',
                            COALESCE(
                                NULLIF((
                                    SELECT txn.transaction_no
                                    FROM mytemr_master.refund AS refund
                                    INNER JOIN `transaction` AS txn
                                        ON txn.id = refund.transaction_id
                                    WHERE refund.id = p_source_id
                                        AND refund.branch_id = gl_branch_id()
                                    LIMIT 1
                                ), ''),
                                CAST(p_source_id AS CHAR)
                            )
                        )
                        WHEN p_source = 'item_history' THEN gl_item_history_remarks(p_source_id)
                        ELSE CONCAT(p_source, ' #', p_source_id)
                    END);
            SELECT journal_entry.id INTO v_je_id
            FROM journal_entry
            WHERE journal_entry.source = p_source
                AND journal_entry.source_id = p_source_id
                AND journal_entry.is_deleted = 0
            LIMIT 1;

            IF v_je_id IS NULL THEN
                INSERT INTO journal_entry (
                    cost_center_id, date, type, remarks, status,
                    total_debit, total_credit, source, source_id,
                    added_by, added_on, is_deleted
                ) VALUES (
                    gl_branch_id(),
                    p_date,
                    LEFT(p_source, 64),
                    v_header_remark,
                    'approved',
                    0,
                    0,
                    p_source,
                    p_source_id,
                    IFNULL(p_added_by, 0),
                    NOW(),
                    0
                );
                SET v_je_id = LAST_INSERT_ID();
            END IF;

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

            UPDATE journal_entry
            SET remarks = v_header_remark,
                total_debit = IFNULL(total_debit, 0) + IFNULL(p_debit, 0),
                total_credit = IFNULL(total_credit, 0) + IFNULL(p_credit, 0),
                updated_on = NOW()
            WHERE id = v_je_id;
        END IF;
    END IF;
END$$

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

DROP FUNCTION IF EXISTS gl_bank_account_id$$

CREATE FUNCTION gl_bank_account_id(p_ids VARCHAR(250))
RETURNS INT
DETERMINISTIC
NO SQL
BEGIN
    DECLARE v_first VARCHAR(64);
    SET v_first = TRIM(SUBSTRING_INDEX(IFNULL(p_ids, ''), ',', 1));
    IF v_first = '' OR v_first NOT REGEXP '^[0-9]+$' THEN
        RETURN NULL;
    END IF;
    RETURN CAST(v_first AS UNSIGNED);
END$$

DROP FUNCTION IF EXISTS gl_ncd_mop_code$$

CREATE FUNCTION gl_ncd_mop_code(p_mop VARCHAR(250))
RETURNS VARCHAR(32)
DETERMINISTIC
NO SQL
BEGIN
    DECLARE v VARCHAR(250);
    SET v = LOWER(REPLACE(IFNULL(p_mop, ''), ' ', ''));
    IF v IN ('cash', 'check', 'cheque') THEN
        RETURN '100-004';
    END IF;
    IF v IN ('card', 'credit', 'creditcard', 'debitcard', '102-192') THEN
        RETURN '102-192';
    END IF;
    RETURN '102-193';
END$$

DROP FUNCTION IF EXISTS gl_ncd_mop_label$$

CREATE FUNCTION gl_ncd_mop_label(p_type VARCHAR(250), p_other VARCHAR(250))
RETURNS VARCHAR(250)
DETERMINISTIC
NO SQL
BEGIN
    IF LOWER(IFNULL(p_type, '')) = 'others' THEN
        RETURN IFNULL(NULLIF(p_other, ''), p_type);
    END IF;
    RETURN p_type;
END$$

DROP FUNCTION IF EXISTS gl_ncd_mop_token$$

CREATE FUNCTION gl_ncd_mop_token(p_mop VARCHAR(250))
RETURNS VARCHAR(250)
DETERMINISTIC
NO SQL
BEGIN
    DECLARE v VARCHAR(250);
    SET v = LOWER(REPLACE(IFNULL(p_mop, ''), ' ', ''));
    IF v IN ('credit', 'creditcard', 'debitcard') THEN
        RETURN 'card';
    END IF;
    IF v = 'cheque' THEN
        RETURN 'check';
    END IF;
    IF v = 'gc' THEN
        RETURN 'giftcheck';
    END IF;
    RETURN NULLIF(v, '');
END$$

DROP FUNCTION IF EXISTS gl_ncd_bank_id$$

CREATE FUNCTION gl_ncd_bank_id(p_mop VARCHAR(250))
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_token VARCHAR(250);
    DECLARE v_bank_id INT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_bank_id = NULL;
    SET v_token = gl_ncd_mop_token(p_mop);
    IF v_token IS NULL THEN
        RETURN NULL;
    END IF;
    SELECT bank.id INTO v_bank_id
    FROM bank
    WHERE bank.is_deleted = 0
        AND bank.mop IS NOT NULL
        AND FIND_IN_SET(v_token, REPLACE(LOWER(bank.mop), ' ', '')) > 0
    ORDER BY bank.id
    LIMIT 1;
    RETURN v_bank_id;
END$$

DROP FUNCTION IF EXISTS gl_payment_has_refund$$

CREATE FUNCTION gl_payment_has_refund(p_payment_id INT)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_cnt INT DEFAULT 0;
    IF IFNULL(p_payment_id, 0) <= 0 THEN
        RETURN 0;
    END IF;
    SELECT COUNT(*) INTO v_cnt
    FROM mytemr_master.refund
    WHERE refund.payment_id = p_payment_id
        AND refund.branch_id = gl_branch_id()
        AND refund.is_deleted = 0;
    RETURN IF(IFNULL(v_cnt, 0) > 0, 1, 0);
END$$

DROP FUNCTION IF EXISTS gl_txn_is_later_cancel$$

CREATE FUNCTION gl_txn_is_later_cancel(p_txn_id INT)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_cnt INT DEFAULT 0;
    IF IFNULL(p_txn_id, 0) <= 0 THEN
        RETURN 0;
    END IF;
    SELECT COUNT(*) INTO v_cnt
    FROM `transaction` AS txn
    WHERE txn.id = p_txn_id
        AND txn.is_deleted = 1
        AND DATE(IFNULL(txn.updated_on, NOW())) <> DATE(txn.transaction_date);
    RETURN IF(IFNULL(v_cnt, 0) > 0, 1, 0);
END$$

DROP FUNCTION IF EXISTS gl_pmm_covered_by_ncd$$

CREATE FUNCTION gl_pmm_covered_by_ncd(p_pmm_id INT)
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_cnt INT DEFAULT 0;
    IF IFNULL(p_pmm_id, 0) <= 0 THEN
        RETURN 0;
    END IF;
    SELECT COUNT(*) INTO v_cnt
    FROM non_cash_deposit AS ncd
    WHERE ncd.payment_multiple_method_id = p_pmm_id
        AND ncd.is_deleted = 0
        AND ncd.status = 'posted';
    RETURN IF(IFNULL(v_cnt, 0) > 0, 1, 0);
END$$

DROP PROCEDURE IF EXISTS gl_upsert_ncd_from_pmm$$

CREATE PROCEDURE gl_upsert_ncd_from_pmm(IN p_pmm_id INT)
proc: BEGIN
    DECLARE v_type VARCHAR(250);
    DECLARE v_other VARCHAR(250);
    DECLARE v_amount DECIMAL(13,2);
    DECLARE v_pay_id INT;
    DECLARE v_ref TEXT;
    DECLARE v_remarks TEXT;
    DECLARE v_added_by INT;
    DECLARE v_deleted INT DEFAULT 0;
    DECLARE v_txn_id INT;
    DECLARE v_txn_no VARCHAR(250);
    DECLARE v_txn_date DATE;
    DECLARE v_mop VARCHAR(250);
    DECLARE v_bank_id INT DEFAULT NULL;
    DECLARE v_fee_id INT DEFAULT NULL;
    DECLARE v_fee_pct DECIMAL(13,2) DEFAULT NULL;
    DECLARE v_ncd_id INT DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_ncd_id = NULL;

    IF IFNULL(p_pmm_id, 0) <= 0 THEN
        LEAVE proc;
    END IF;

    SELECT pmm.type,
        pmm.other_payment_details,
        pmm.amount,
        pmm.payment_id,
        pmm.reference_no,
        pmm.remarks,
        pmm.added_by,
        pmm.is_deleted,
        pay.transaction_id,
        txn.transaction_no,
        DATE(txn.transaction_date)
    INTO v_type, v_other, v_amount, v_pay_id, v_ref, v_remarks,
        v_added_by, v_deleted, v_txn_id, v_txn_no, v_txn_date
    FROM payment_multiple_method AS pmm
    LEFT JOIN payment AS pay ON pay.id = pmm.payment_id
    LEFT JOIN `transaction` AS txn ON txn.id = pay.transaction_id
    WHERE pmm.id = p_pmm_id
    LIMIT 1;

    IF v_txn_id IS NULL
        OR IFNULL(v_deleted, 0) = 1
        OR gl_mop_code(v_type, v_other) = '100-004'
        OR gl_is_refund_new_payment(v_pay_id) = 1
    THEN
        UPDATE non_cash_deposit
        SET is_deleted = 1,
            updated_on = NOW()
        WHERE payment_multiple_method_id = p_pmm_id
            AND is_deleted = 0;
        LEAVE proc;
    END IF;

    SET v_mop = gl_ncd_mop_label(v_type, v_other);
    SET v_bank_id = gl_ncd_bank_id(v_mop);
    IF v_bank_id IS NOT NULL THEN
        SELECT fee.id, fee.percentage
        INTO v_fee_id, v_fee_pct
        FROM bank_service_fee AS fee
        WHERE fee.bank_id = v_bank_id
            AND fee.is_deleted = 0
        ORDER BY fee.id
        LIMIT 1;
    END IF;

    SELECT ncd.id INTO v_ncd_id
    FROM non_cash_deposit AS ncd
    WHERE ncd.payment_multiple_method_id = p_pmm_id
        AND ncd.is_deleted = 0
    LIMIT 1;

    IF v_ncd_id IS NULL THEN
        INSERT INTO non_cash_deposit (
            transaction_id, transaction_no, transaction_date,
            payment_id, payment_multiple_method_id,
            bank_id, bank_service_fee_id, bank_service_fee_percentage,
            amount, mop, reference_no, remarks,
            status, added_by, added_on, is_deleted
        ) VALUES (
            v_txn_id,
            COALESCE(NULLIF(v_txn_no, ''), CAST(v_txn_id AS CHAR)),
            v_txn_date,
            v_pay_id,
            p_pmm_id,
            v_bank_id,
            v_fee_id,
            v_fee_pct,
            IFNULL(v_amount, 0),
            v_mop,
            v_ref,
            v_remarks,
            'unposted',
            IFNULL(v_added_by, 0),
            NOW(),
            0
        );
    ELSE
        UPDATE non_cash_deposit
        SET transaction_id = v_txn_id,
            transaction_no = COALESCE(NULLIF(v_txn_no, ''), CAST(v_txn_id AS CHAR)),
            transaction_date = v_txn_date,
            payment_id = v_pay_id,
            bank_id = v_bank_id,
            bank_service_fee_id = v_fee_id,
            bank_service_fee_percentage = v_fee_pct,
            amount = IFNULL(v_amount, 0),
            mop = v_mop,
            reference_no = v_ref,
            remarks = v_remarks,
            updated_on = NOW()
        WHERE id = v_ncd_id;
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_csr_mop$$

CREATE PROCEDURE gl_post_csr_mop(IN p_id INT)
proc: BEGIN
    DECLARE v_txn_id INT;
    DECLARE v_pay_id INT;

    SELECT pay.transaction_id, pay.id
    INTO v_txn_id, v_pay_id
    FROM payment_multiple_method AS pmm
    INNER JOIN payment AS pay ON pay.id = pmm.payment_id
    WHERE pmm.id = p_id
    LIMIT 1;

    IF gl_payment_has_refund(v_pay_id) = 1 THEN
        LEAVE proc;
    END IF;
    IF gl_txn_is_later_cancel(v_txn_id) = 1 THEN
        LEAVE proc;
    END IF;

    CALL gl_clear('payment_multiple_method', p_id);

    IF gl_is_refund_new_payment(v_pay_id) = 0
        AND gl_pmm_covered_by_ncd(p_id) = 0
    THEN
        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'payment_multiple_method',
            pmm.id,
            asset_account.id,
            DATE(txn.transaction_date),
            gl_pmm_net_amount(pmm.id),
            0,
            CONCAT(
                CASE gl_mop_code(pmm.type, pmm.other_payment_details)
                    WHEN '100-004' THEN 'CSR cash collection #'
                    WHEN '102-192' THEN 'CSR cards collection #'
                    WHEN '102-193' THEN 'CSR GCash/Maya collection #'
                    WHEN '102-113B' THEN 'CSR company wallet unbilled #'
                    ELSE 'CSR collection #'
                END,
                COALESCE(txn.transaction_no, pay.id)
            ),
            pmm.added_by,
            NOW(),
            0
        FROM payment_multiple_method AS pmm
        INNER JOIN payment AS pay ON pay.id = pmm.payment_id
        INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id AND txn.is_deleted = 0
        INNER JOIN mytemr_master.account_type AS asset_account
            ON asset_account.code = gl_mop_code(pmm.type, pmm.other_payment_details)
            AND asset_account.parent_id IS NULL
            AND asset_account.is_deleted = 0
        WHERE pmm.id = p_id
            AND (pmm.is_deleted = 0 OR gl_payment_has_refund(pmm.payment_id) = 1)
            AND gl_pmm_net_amount(pmm.id) > 0
            AND IFNULL(txn.is_worklist, 0) = 0
            AND gl_mop_code(pmm.type, pmm.other_payment_details) IS NOT NULL;

        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'payment_multiple_method',
            pmm.id,
            revenue_account.id,
            DATE(txn.transaction_date),
            0,
            ROUND(gl_pmm_net_amount(pmm.id) * share.share_amt / totals.txn_total, 2),
            CONCAT('CSR sale #', COALESCE(txn.transaction_no, pay.id)),
            pmm.added_by,
            NOW(),
            0
        FROM payment_multiple_method AS pmm
        INNER JOIN payment AS pay ON pay.id = pmm.payment_id
        INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id AND txn.is_deleted = 0
        INNER JOIN (
            SELECT b.transaction_id,
                CASE
                    WHEN gl_line_revenue_code(bd.id) IN ('400-010', '400-020')
                    THEN gl_txn_group_revenue_code(b.transaction_id)
                    ELSE gl_line_revenue_code(bd.id)
                END AS code,
                SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)) AS share_amt
            FROM booking_details AS bd
            INNER JOIN booking AS b ON b.id = bd.booking_id
                AND b.is_deleted = 0
                AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
            WHERE bd.is_deleted = 0
                AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
            GROUP BY b.transaction_id,
                CASE
                    WHEN gl_line_revenue_code(bd.id) IN ('400-010', '400-020')
                    THEN gl_txn_group_revenue_code(b.transaction_id)
                    ELSE gl_line_revenue_code(bd.id)
                END
        ) AS share ON share.transaction_id = txn.id
            AND share.share_amt > 0
        INNER JOIN (
            SELECT b.transaction_id,
                SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)) AS txn_total
            FROM booking_details AS bd
            INNER JOIN booking AS b ON b.id = bd.booking_id
                AND b.is_deleted = 0
                AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
            WHERE bd.is_deleted = 0
                AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
            GROUP BY b.transaction_id
        ) AS totals ON totals.transaction_id = txn.id
            AND totals.txn_total > 0
        INNER JOIN mytemr_master.account_type AS revenue_account
            ON revenue_account.code = share.code
            AND revenue_account.parent_id IS NULL
            AND revenue_account.is_deleted = 0
        WHERE pmm.id = p_id
            AND (pmm.is_deleted = 0 OR gl_payment_has_refund(pmm.payment_id) = 1)
            AND gl_pmm_net_amount(pmm.id) > 0
            AND IFNULL(txn.is_worklist, 0) = 0
            AND gl_mop_code(pmm.type, pmm.other_payment_details) IS NOT NULL;

        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'payment_multiple_method',
            pmm.id,
            revenue_account.id,
            DATE(txn.transaction_date),
            0,
            gl_pmm_net_amount(pmm.id),
            CONCAT('CSR sale #', COALESCE(txn.transaction_no, pay.id)),
            pmm.added_by,
            NOW(),
            0
        FROM payment_multiple_method AS pmm
        INNER JOIN payment AS pay ON pay.id = pmm.payment_id
        INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id AND txn.is_deleted = 0
        INNER JOIN mytemr_master.account_type AS revenue_account
            ON revenue_account.code = gl_revenue_code(txn.id)
            AND revenue_account.parent_id IS NULL
            AND revenue_account.is_deleted = 0
        WHERE pmm.id = p_id
            AND (pmm.is_deleted = 0 OR gl_payment_has_refund(pmm.payment_id) = 1)
            AND gl_pmm_net_amount(pmm.id) > 0
            AND IFNULL(txn.is_worklist, 0) = 0
            AND gl_mop_code(pmm.type, pmm.other_payment_details) IS NOT NULL
            AND NOT EXISTS (
                SELECT 1
                FROM booking_details AS bd
                INNER JOIN booking AS b ON b.id = bd.booking_id
                    AND b.is_deleted = 0
                    AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
                WHERE bd.is_deleted = 0
                    AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
                    AND b.transaction_id = txn.id
                    AND COALESCE(NULLIF(bd.amount, 0), bd.price, 0) > 0
            );
    END IF;

    IF v_txn_id IS NOT NULL THEN
        CALL gl_post_unbilled(v_txn_id);
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_csr_discount$$

CREATE PROCEDURE gl_post_csr_discount(IN p_payment_id INT)
proc: BEGIN
    DECLARE v_txn_id INT DEFAULT NULL;

    SELECT payment.transaction_id INTO v_txn_id
    FROM payment
    WHERE payment.id = p_payment_id
    LIMIT 1;

    IF gl_payment_has_refund(p_payment_id) = 1 THEN
        LEAVE proc;
    END IF;
    IF gl_txn_is_later_cancel(v_txn_id) = 1 THEN
        LEAVE proc;
    END IF;

    CALL gl_clear('payment', p_payment_id);
    IF gl_is_refund_new_payment(p_payment_id) = 1 THEN
        LEAVE proc;
    END IF;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'payment',
        pay.id,
        gl_account_id('440-000'),
        DATE(txn.transaction_date),
        pay.discount,
        0,
        CONCAT('CSR discount #', COALESCE(txn.transaction_no, pay.id)),
        pay.added_by,
        NOW(),
        0
    FROM payment AS pay
    INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id AND txn.is_deleted = 0
    WHERE pay.id = p_payment_id
        AND pay.is_deleted = 0
        AND IFNULL(pay.discount, 0) > 0
        AND IFNULL(txn.is_worklist, 0) = 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'payment',
        pay.id,
        revenue_account.id,
        DATE(txn.transaction_date),
        0,
        ROUND(pay.discount * share.share_amt / totals.txn_total, 2),
        CONCAT('CSR sale discount #', COALESCE(txn.transaction_no, pay.id)),
        pay.added_by,
        NOW(),
        0
    FROM payment AS pay
    INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id AND txn.is_deleted = 0
    INNER JOIN (
        SELECT b.transaction_id,
            CASE
                WHEN gl_line_revenue_code(bd.id) IN ('400-010', '400-020')
                THEN gl_txn_group_revenue_code(b.transaction_id)
                ELSE gl_line_revenue_code(bd.id)
            END AS code,
            SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)) AS share_amt
        FROM booking_details AS bd
        INNER JOIN booking AS b ON b.id = bd.booking_id
            AND b.is_deleted = 0
            AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
        WHERE bd.is_deleted = 0
            AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
        GROUP BY b.transaction_id,
            CASE
                WHEN gl_line_revenue_code(bd.id) IN ('400-010', '400-020')
                THEN gl_txn_group_revenue_code(b.transaction_id)
                ELSE gl_line_revenue_code(bd.id)
            END
    ) AS share ON share.transaction_id = txn.id
        AND share.share_amt > 0
    INNER JOIN (
        SELECT b.transaction_id,
            SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)) AS txn_total
        FROM booking_details AS bd
        INNER JOIN booking AS b ON b.id = bd.booking_id
            AND b.is_deleted = 0
            AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
        WHERE bd.is_deleted = 0
            AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
        GROUP BY b.transaction_id
    ) AS totals ON totals.transaction_id = txn.id
        AND totals.txn_total > 0
    INNER JOIN mytemr_master.account_type AS revenue_account
        ON revenue_account.code = share.code
        AND revenue_account.parent_id IS NULL
        AND revenue_account.is_deleted = 0
    WHERE pay.id = p_payment_id
        AND pay.is_deleted = 0
        AND IFNULL(pay.discount, 0) > 0
        AND IFNULL(txn.is_worklist, 0) = 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'payment',
        pay.id,
        revenue_account.id,
        DATE(txn.transaction_date),
        0,
        pay.discount,
        CONCAT('CSR sale discount #', COALESCE(txn.transaction_no, pay.id)),
        pay.added_by,
        NOW(),
        0
    FROM payment AS pay
    INNER JOIN `transaction` AS txn ON txn.id = pay.transaction_id AND txn.is_deleted = 0
    INNER JOIN mytemr_master.account_type AS revenue_account
        ON revenue_account.code = gl_revenue_code(txn.id)
        AND revenue_account.parent_id IS NULL
        AND revenue_account.is_deleted = 0
    WHERE pay.id = p_payment_id
        AND pay.is_deleted = 0
        AND IFNULL(pay.discount, 0) > 0
        AND IFNULL(txn.is_worklist, 0) = 0
        AND NOT EXISTS (
            SELECT 1
            FROM booking_details AS bd
            INNER JOIN booking AS b ON b.id = bd.booking_id
                AND b.is_deleted = 0
                AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
            WHERE bd.is_deleted = 0
                AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
                AND b.transaction_id = txn.id
                AND COALESCE(NULLIF(bd.amount, 0), bd.price, 0) > 0
        );
END$$

DROP PROCEDURE IF EXISTS gl_post_refund$$

CREATE PROCEDURE gl_post_refund(IN p_id INT)
BEGIN
    CALL gl_clear('refund', p_id);

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'refund',
        refund.id,
        gl_account_id('440-010'),
        COALESCE(DATE(refund.approved_on), DATE(refund.updated_on), DATE(refund.added_on), CURDATE()),
        refund.amount,
        0,
        CONCAT(
            'Sales return refund #',
            COALESCE(NULLIF(txn.transaction_no, ''), refund.id)
        ),
        IFNULL(refund.added_by, 0),
        NOW(),
        0
    FROM mytemr_master.refund AS refund
    LEFT JOIN `transaction` AS txn ON txn.id = refund.transaction_id
    WHERE refund.id = p_id
        AND refund.branch_id = gl_branch_id()
        AND refund.is_deleted = 0
        AND LOWER(IFNULL(refund.status, '')) = 'refunded'
        AND IFNULL(refund.amount, 0) > 0
        AND gl_account_id('440-010') IS NOT NULL;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'refund',
        refund.id,
        gl_account_id('201-111'),
        COALESCE(DATE(refund.approved_on), DATE(refund.updated_on), DATE(refund.added_on), CURDATE()),
        0,
        refund.amount,
        CONCAT(
            'Sales return refund #',
            COALESCE(NULLIF(txn.transaction_no, ''), refund.id)
        ),
        IFNULL(refund.added_by, 0),
        NOW(),
        0
    FROM mytemr_master.refund AS refund
    LEFT JOIN `transaction` AS txn ON txn.id = refund.transaction_id
    WHERE refund.id = p_id
        AND refund.branch_id = gl_branch_id()
        AND refund.is_deleted = 0
        AND LOWER(IFNULL(refund.status, '')) = 'refunded'
        AND IFNULL(refund.amount, 0) > 0
        AND gl_account_id('201-111') IS NOT NULL;
END$$

DROP PROCEDURE IF EXISTS gl_post_unbilled$$

CREATE PROCEDURE gl_post_unbilled(IN p_transaction_id INT)
proc: BEGIN
    DECLARE v_unbilled DECIMAL(13,4) DEFAULT 0;
    DECLARE v_eligible INT DEFAULT 0;
    DECLARE v_charge_to_ar DECIMAL(13,4) DEFAULT 0;
    DECLARE v_charge_to_retail DECIMAL(13,4) DEFAULT 0;
    DECLARE v_unbilled_rest DECIMAL(13,4) DEFAULT 0;
    DECLARE v_has_yakap INT DEFAULT 0;

    IF gl_txn_is_later_cancel(p_transaction_id) = 1 THEN
        LEAVE proc;
    END IF;

    CALL gl_clear('transaction', p_transaction_id);

    SELECT GREATEST(
        0,
        COALESCE(NULLIF(txn.grand_total, 0), IFNULL(line_totals.line_amount, 0), 0)
            - IFNULL(collected.collected_amount, 0)
    ),
    CASE
        WHEN txn.is_deleted = 0
            AND IFNULL(txn.is_worklist, 0) = 0
            AND IFNULL(txn.is_billed, 0) = 0
            AND txn.billing_id IS NULL
            AND (
                IFNULL(txn.hmo_id, 0) > 0
                OR IFNULL(txn.endorsement_id, 0) > 0
                OR EXISTS (
                    SELECT 1 FROM booking
                    WHERE booking.transaction_id = txn.id
                        AND booking.is_deleted = 0
                        AND (
                            IFNULL(booking.company_id, 0) > 0
                            OR IFNULL(booking.hmo_id, 0) > 0
                            OR IFNULL(booking.charge_to, 0) > 0
                        )
                )
                OR EXISTS (
                    SELECT 1 FROM payment
                    WHERE payment.transaction_id = txn.id
                        AND payment.is_deleted = 0
                        AND IFNULL(payment.charge_to, 0) > 0
                )
            )
            AND NOT EXISTS (
                SELECT 1
                FROM mytemr_master.billing_entry
                INNER JOIN mytemr_master.billing ON billing.id = billing_entry.billing_id
                    AND billing.is_deleted = 0
                    AND billing.status IN ('open_bill', 'closed_bill')
                WHERE billing_entry.transaction_id = txn.id
                    AND billing_entry.branch_id = gl_branch_id()
                    AND billing_entry.is_deleted = 0
                    AND billing_entry.status IN ('open_bill', 'closed_bill')
            )
        THEN 1
        ELSE 0
    END
    INTO v_unbilled, v_eligible
    FROM `transaction` AS txn
    LEFT JOIN (
        SELECT b.transaction_id,
            COALESCE(SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)), 0) AS line_amount
        FROM booking_details AS bd
        INNER JOIN booking AS b ON b.id = bd.booking_id
            AND b.is_deleted = 0
            AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
        WHERE bd.is_deleted = 0
            AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
            AND b.transaction_id = p_transaction_id
        GROUP BY b.transaction_id
    ) AS line_totals ON line_totals.transaction_id = txn.id
    LEFT JOIN (
        SELECT payment.transaction_id,
            COALESCE(SUM(gl_pmm_net_amount(payment_multiple_method.id)), 0) AS collected_amount
        FROM payment
        INNER JOIN payment_multiple_method ON payment_multiple_method.payment_id = payment.id
            AND (
                payment_multiple_method.is_deleted = 0
                OR gl_payment_has_refund(payment_multiple_method.payment_id) = 1
            )
            AND gl_mop_code(payment_multiple_method.type, payment_multiple_method.other_payment_details) IS NOT NULL
        WHERE (
                payment.is_deleted = 0
                OR gl_payment_has_refund(payment.id) = 1
            )
            AND payment.transaction_id = p_transaction_id
        GROUP BY payment.transaction_id
    ) AS collected ON collected.transaction_id = txn.id
    WHERE txn.id = p_transaction_id;

    IF IFNULL(v_eligible, 0) = 0 THEN
        SET v_unbilled = 0;
    END IF;

    SELECT COALESCE(SUM(line.amt), 0)
    INTO v_charge_to_ar
    FROM (
        SELECT COALESCE(MAX(es.pro_rate_price), MAX(NULLIF(bd.amount, 0)), MAX(bd.price), 0) AS amt
        FROM booking_details AS bd
        INNER JOIN booking AS b ON b.id = bd.booking_id
            AND b.is_deleted = 0
            AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
        LEFT JOIN mytemr_master.endorsement_service AS es
            ON es.endorsement_id = bd.endorsement_id
            AND es.is_deleted = 0
            AND es.type_id = bd.test_id
            AND es.type = CASE
                WHEN bd.type = 'lab' THEN 'lab_test'
                ELSE bd.type
            END
        WHERE bd.is_deleted = 0
            AND b.transaction_id = p_transaction_id
            AND IFNULL(bd.charge_to, 0) > 0
            AND IFNULL(bd.charge_to, 0) <> 3210
        GROUP BY bd.id
    ) AS line;

    SELECT COALESCE(SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)), 0)
    INTO v_charge_to_retail
    FROM booking_details AS bd
    INNER JOIN booking AS b ON b.id = bd.booking_id
        AND b.is_deleted = 0
        AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
    WHERE bd.is_deleted = 0
        AND b.transaction_id = p_transaction_id
        AND IFNULL(bd.charge_to, 0) > 0
        AND IFNULL(bd.charge_to, 0) <> 3210;

    IF IFNULL(v_eligible, 0) = 0 THEN
        SET v_charge_to_ar = 0;
        SET v_charge_to_retail = 0;
    END IF;

    IF IFNULL(v_charge_to_ar, 0) > 0 THEN
        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'transaction',
            txn.id,
            gl_account_id('102-113B'),
            DATE(txn.transaction_date),
            v_charge_to_ar,
            0,
            CONCAT('Charge-to AR unbilled #', COALESCE(txn.transaction_no, txn.id)),
            txn.added_by,
            NOW(),
            0
        FROM `transaction` AS txn
        WHERE txn.id = p_transaction_id
            AND gl_account_id('102-113B') IS NOT NULL;

        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'transaction',
            txn.id,
            revenue_account.id,
            DATE(txn.transaction_date),
            0,
            SUM(line.amt),
            CONCAT('Charge-to sale #', COALESCE(txn.transaction_no, txn.id)),
            txn.added_by,
            NOW(),
            0
        FROM `transaction` AS txn
        INNER JOIN (
            SELECT b.transaction_id,
                gl_line_revenue_code(bd.id) AS code,
                COALESCE(MAX(es.pro_rate_price), MAX(NULLIF(bd.amount, 0)), MAX(bd.price), 0) AS amt
            FROM booking_details AS bd
            INNER JOIN booking AS b ON b.id = bd.booking_id
                AND b.is_deleted = 0
                AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
            LEFT JOIN mytemr_master.endorsement_service AS es
                ON es.endorsement_id = bd.endorsement_id
                AND es.is_deleted = 0
                AND es.type_id = bd.test_id
                AND es.type = CASE
                    WHEN bd.type = 'lab' THEN 'lab_test'
                    ELSE bd.type
                END
            WHERE bd.is_deleted = 0
                AND b.transaction_id = p_transaction_id
                AND IFNULL(bd.charge_to, 0) > 0
                AND IFNULL(bd.charge_to, 0) <> 3210
            GROUP BY bd.id
        ) AS line ON line.transaction_id = txn.id
            AND line.amt > 0
        INNER JOIN mytemr_master.account_type AS revenue_account
            ON revenue_account.code = line.code
            AND revenue_account.parent_id IS NULL
            AND revenue_account.is_deleted = 0
        WHERE txn.id = p_transaction_id
        GROUP BY txn.id, txn.transaction_no, txn.transaction_date, txn.added_by,
            line.code, revenue_account.id;
    END IF;

    IF IFNULL(v_eligible, 0) = 1 THEN
        SELECT COUNT(*)
        INTO v_has_yakap
        FROM booking_details AS bd
        INNER JOIN booking AS b ON b.id = bd.booking_id
            AND b.is_deleted = 0
            AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
        WHERE bd.is_deleted = 0
            AND b.transaction_id = p_transaction_id
            AND IFNULL(bd.charge_to, 0) = 3210;
    END IF;

    IF IFNULL(v_has_yakap, 0) > 0 THEN
        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'transaction',
            txn.id,
            gl_account_id('102-113B'),
            DATE(txn.transaction_date),
            0,
            0,
            CONCAT('Charge-to YAKAP unbilled #', COALESCE(txn.transaction_no, txn.id)),
            txn.added_by,
            NOW(),
            0
        FROM `transaction` AS txn
        WHERE txn.id = p_transaction_id
            AND gl_account_id('102-113B') IS NOT NULL;

        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'transaction',
            txn.id,
            gl_account_id('400-060'),
            DATE(txn.transaction_date),
            0,
            0,
            CONCAT('Charge-to YAKAP sale #', COALESCE(txn.transaction_no, txn.id)),
            txn.added_by,
            NOW(),
            0
        FROM `transaction` AS txn
        WHERE txn.id = p_transaction_id
            AND gl_account_id('400-060') IS NOT NULL;
    END IF;

    SET v_unbilled_rest = GREATEST(0, IFNULL(v_unbilled, 0) - IFNULL(v_charge_to_retail, 0));

    IF IFNULL(v_unbilled_rest, 0) > 0 THEN
        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'transaction',
            txn.id,
            gl_account_id('102-113B'),
            DATE(txn.transaction_date),
            v_unbilled_rest,
            0,
            CONCAT('HMO/Corp unbilled #', COALESCE(txn.transaction_no, txn.id)),
            txn.added_by,
            NOW(),
            0
        FROM `transaction` AS txn
        WHERE txn.id = p_transaction_id;

        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'transaction',
            txn.id,
            revenue_account.id,
            DATE(txn.transaction_date),
            0,
            ROUND(v_unbilled_rest * share.share_amt / totals.txn_total, 2),
            CONCAT('CSR unbilled sale #', COALESCE(txn.transaction_no, txn.id)),
            txn.added_by,
            NOW(),
            0
        FROM `transaction` AS txn
        INNER JOIN (
            SELECT b.transaction_id,
                CASE
                    WHEN gl_line_revenue_code(bd.id) IN ('400-010', '400-020')
                    THEN gl_txn_group_revenue_code(b.transaction_id)
                    ELSE gl_line_revenue_code(bd.id)
                END AS code,
                SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)) AS share_amt
            FROM booking_details AS bd
            INNER JOIN booking AS b ON b.id = bd.booking_id
                AND b.is_deleted = 0
                AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
            WHERE bd.is_deleted = 0
                AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                AND IFNULL(bd.charge_to, 0) = 0
            GROUP BY b.transaction_id,
                CASE
                    WHEN gl_line_revenue_code(bd.id) IN ('400-010', '400-020')
                    THEN gl_txn_group_revenue_code(b.transaction_id)
                    ELSE gl_line_revenue_code(bd.id)
                END
        ) AS share ON share.transaction_id = txn.id
            AND share.share_amt > 0
        INNER JOIN (
            SELECT b.transaction_id,
                SUM(COALESCE(NULLIF(bd.amount, 0), bd.price, 0)) AS txn_total
            FROM booking_details AS bd
            INNER JOIN booking AS b ON b.id = bd.booking_id
                AND b.is_deleted = 0
                AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
            WHERE bd.is_deleted = 0
                AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                AND IFNULL(bd.charge_to, 0) = 0
            GROUP BY b.transaction_id
        ) AS totals ON totals.transaction_id = txn.id
            AND totals.txn_total > 0
        INNER JOIN mytemr_master.account_type AS revenue_account
            ON revenue_account.code = share.code
            AND revenue_account.parent_id IS NULL
            AND revenue_account.is_deleted = 0
        WHERE txn.id = p_transaction_id;

        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'transaction',
            txn.id,
            revenue_account.id,
            DATE(txn.transaction_date),
            0,
            v_unbilled_rest,
            CONCAT('CSR unbilled sale #', COALESCE(txn.transaction_no, txn.id)),
            txn.added_by,
            NOW(),
            0
        FROM `transaction` AS txn
        INNER JOIN mytemr_master.account_type AS revenue_account
            ON revenue_account.code = gl_revenue_code(txn.id)
            AND revenue_account.parent_id IS NULL
            AND revenue_account.is_deleted = 0
        WHERE txn.id = p_transaction_id
            AND NOT EXISTS (
                SELECT 1
                FROM booking_details AS bd
                INNER JOIN booking AS b ON b.id = bd.booking_id
                    AND b.is_deleted = 0
                    AND LOWER(IFNULL(b.status, '')) NOT IN ('cancelled', 'canceled', 'void', 'disapproved')
                WHERE bd.is_deleted = 0
                    AND LOWER(IFNULL(bd.type, '')) NOT IN ('wallet', 'company_wallet')
                    AND IFNULL(bd.charge_to, 0) = 0
                    AND b.transaction_id = txn.id
                    AND COALESCE(NULLIF(bd.amount, 0), bd.price, 0) > 0
            );
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_sales_return$$

CREATE PROCEDURE gl_post_sales_return(IN p_txn_id INT)
proc: BEGIN
    CALL gl_clear('sales_return', p_txn_id);

    IF gl_txn_is_later_cancel(p_txn_id) = 0 THEN
        LEAVE proc;
    END IF;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'sales_return',
        txn.id,
        gl_account_id('440-010'),
        DATE(IFNULL(txn.updated_on, NOW())),
        SUM(IFNULL(gl.credit, 0)),
        0,
        CONCAT('Sales return cancel #', COALESCE(NULLIF(txn.transaction_no, ''), txn.id)),
        IFNULL(txn.added_by, 0),
        NOW(),
        0
    FROM `transaction` AS txn
    INNER JOIN general_ledger AS gl
        ON gl.is_deleted = 0
        AND IFNULL(gl.credit, 0) > 0
        AND (
            (gl.source = 'transaction' AND gl.source_id = txn.id)
            OR (
                gl.source = 'payment'
                AND gl.source_id IN (
                    SELECT payment.id FROM payment WHERE payment.transaction_id = txn.id
                )
            )
            OR (
                gl.source = 'payment_multiple_method'
                AND gl.source_id IN (
                    SELECT pmm.id
                    FROM payment_multiple_method AS pmm
                    INNER JOIN payment AS pay ON pay.id = pmm.payment_id
                    WHERE pay.transaction_id = txn.id
                )
            )
        )
    WHERE txn.id = p_txn_id
        AND gl_account_id('440-010') IS NOT NULL
    GROUP BY txn.id, txn.transaction_no, txn.updated_on, txn.added_by
    HAVING SUM(IFNULL(gl.credit, 0)) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'sales_return',
        txn.id,
        gl.account_type_id,
        DATE(IFNULL(txn.updated_on, NOW())),
        0,
        SUM(IFNULL(gl.debit, 0)),
        CONCAT('Sales return cancel #', COALESCE(NULLIF(txn.transaction_no, ''), txn.id)),
        IFNULL(txn.added_by, 0),
        NOW(),
        0
    FROM `transaction` AS txn
    INNER JOIN general_ledger AS gl
        ON gl.is_deleted = 0
        AND IFNULL(gl.debit, 0) > 0
        AND gl.account_type_id IS NOT NULL
        AND (
            (gl.source = 'transaction' AND gl.source_id = txn.id)
            OR (
                gl.source = 'payment'
                AND gl.source_id IN (
                    SELECT payment.id FROM payment WHERE payment.transaction_id = txn.id
                )
            )
            OR (
                gl.source = 'payment_multiple_method'
                AND gl.source_id IN (
                    SELECT pmm.id
                    FROM payment_multiple_method AS pmm
                    INNER JOIN payment AS pay ON pay.id = pmm.payment_id
                    WHERE pay.transaction_id = txn.id
                )
            )
        )
    WHERE txn.id = p_txn_id
    GROUP BY txn.id, txn.transaction_no, txn.updated_on, txn.added_by, gl.account_type_id
    HAVING SUM(IFNULL(gl.debit, 0)) > 0;
END$$

DROP PROCEDURE IF EXISTS gl_post_transaction$$

CREATE PROCEDURE gl_post_transaction(IN p_id INT)
proc: BEGIN
    DECLARE v_live INT DEFAULT 0;

    IF gl_txn_is_later_cancel(p_id) = 1 THEN
        CALL gl_post_sales_return(p_id);
        LEAVE proc;
    END IF;

    CALL gl_clear('transaction', p_id);
    CALL gl_clear('sales_return', p_id);

    UPDATE general_ledger
    INNER JOIN payment ON payment.id = general_ledger.source_id
        AND general_ledger.source = 'payment'
    SET general_ledger.is_deleted = 1,
        general_ledger.updated_on = NOW()
    WHERE payment.transaction_id = p_id
        AND general_ledger.is_deleted = 0
        AND gl_payment_has_refund(payment.id) = 0;

    UPDATE general_ledger
    INNER JOIN payment_multiple_method ON payment_multiple_method.id = general_ledger.source_id
        AND general_ledger.source = 'payment_multiple_method'
    INNER JOIN payment ON payment.id = payment_multiple_method.payment_id
    SET general_ledger.is_deleted = 1,
        general_ledger.updated_on = NOW()
    WHERE payment.transaction_id = p_id
        AND general_ledger.is_deleted = 0
        AND gl_payment_has_refund(payment.id) = 0;

    CALL gl_rebuild_txn_je(p_id);

    SELECT COUNT(*) INTO v_live
    FROM `transaction`
    WHERE `transaction`.id = p_id
        AND `transaction`.is_deleted = 0
        AND IFNULL(`transaction`.is_worklist, 0) = 0;

    IF v_live > 0 THEN
        CALL gl_post_unbilled(p_id);

        BEGIN
            DECLARE v_done INT DEFAULT 0;
            DECLARE v_pay_id INT;
            DECLARE cur_pay CURSOR FOR
                SELECT payment.id FROM payment WHERE payment.transaction_id = p_id;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

            OPEN cur_pay;
            pay_loop: LOOP
                FETCH cur_pay INTO v_pay_id;
                IF v_done = 1 THEN
                    LEAVE pay_loop;
                END IF;
                CALL gl_post_csr_discount(v_pay_id);
                SET v_done = 0;
            END LOOP;
            CLOSE cur_pay;
        END;

        BEGIN
            DECLARE v_done INT DEFAULT 0;
            DECLARE v_pmm_id INT;
            DECLARE cur_pmm CURSOR FOR
                SELECT pmm.id
                FROM payment_multiple_method AS pmm
                INNER JOIN payment AS pay ON pay.id = pmm.payment_id
                WHERE pay.transaction_id = p_id;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

            OPEN cur_pmm;
            pmm_loop: LOOP
                FETCH cur_pmm INTO v_pmm_id;
                IF v_done = 1 THEN
                    LEAVE pmm_loop;
                END IF;
                CALL gl_post_csr_mop(v_pmm_id);
                SET v_done = 0;
            END LOOP;
            CLOSE cur_pmm;
        END;

        BEGIN
            DECLARE v_done INT DEFAULT 0;
            DECLARE v_refund_id INT;
            DECLARE cur_refund CURSOR FOR
                SELECT refund.id
                FROM mytemr_master.refund
                WHERE refund.transaction_id = p_id
                    AND refund.branch_id = gl_branch_id();
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

            OPEN cur_refund;
            refund_loop: LOOP
                FETCH cur_refund INTO v_refund_id;
                IF v_done = 1 THEN
                    LEAVE refund_loop;
                END IF;
                CALL gl_post_refund(v_refund_id);
                SET v_done = 0;
            END LOOP;
            CLOSE cur_refund;
        END;
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_item_history$$

CREATE PROCEDURE gl_post_item_history(IN p_id INT)
BEGIN
    DECLARE v_inv_code VARCHAR(32);
    DECLARE v_signed DECIMAL(13,4) DEFAULT 0;
    DECLARE v_amount DECIMAL(13,4) DEFAULT 0;

    CALL gl_clear('item_history', p_id);

    SELECT CASE
            WHEN ih.activity = 'pharmacy' THEN '104-020B'
            ELSE '104-030'
        END,
        CASE WHEN ih.activity = 'Blindsheet' AND (sheet.id IS NULL OR IFNULL(ih.branch_id, -1) <> IFNULL(gl_branch_id(), -2)) THEN 0
            ELSE ABS(ih.quantity) * IFNULL(item.weighted_cost, 0) END,
        CASE
            WHEN ih.activity IN ('Transfer In') THEN ABS(ih.quantity)
            WHEN ih.activity IN ('perform', 'Machine Run', 'lab supplies', 'pharmacy', 'Transfer Out') THEN -ABS(ih.quantity)
            WHEN sheet.id IS NOT NULL THEN IFNULL(sheet.physical_count, 0) - IFNULL(sheet.prev_qty, 0)
            WHEN adj.id IS NOT NULL THEN IFNULL(adj.physical_count, 0) - IFNULL(adj.prev_qty, 0)
            WHEN IFNULL(ih.adjustment_type, '') = 'returned' THEN -ABS(ih.quantity)
            ELSE -ABS(ih.quantity)
        END
    INTO v_inv_code, v_amount, v_signed
    FROM item_history AS ih
    LEFT JOIN branch_item_blindsheet AS sheet ON sheet.id = ih.activity_id
        AND ih.activity = 'Blindsheet'
        AND sheet.branch_item_id = ih.branch_item_id
        AND sheet.item_id = ih.item_id
        AND sheet.branch_id = ih.branch_id
        AND sheet.is_deleted = 0
    LEFT JOIN branch_item_adjustment AS adj ON adj.id = ih.activity_id
        AND ih.activity = 'Adjustment'
        AND adj.is_deleted = 0
    LEFT JOIN mytemr_master.item AS item ON item.id = ih.item_id
        AND item.is_deleted = 0
    WHERE ih.id = p_id
    LIMIT 1;

    IF IFNULL(v_amount, 0) <= 0 THEN
        SET v_amount = 0;
    END IF;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'item_history',
        ih.id,
        CASE
            WHEN ih.activity = 'Transfer In' THEN gl_account_id(v_inv_code)
            WHEN ih.activity = 'Transfer Out' THEN NULL
            WHEN ih.activity = 'Adjustment' AND IFNULL(ih.adjustment_type, '') = 'returned'
                THEN gl_account_id('201-111')
            WHEN ih.activity IN ('Adjustment', 'Blindsheet') AND v_signed > 0 THEN gl_account_id(v_inv_code)
            WHEN ih.activity IN ('perform', 'Machine Run', 'lab supplies')
                THEN gl_account_id('500-205')
            ELSE COALESCE(item.account_type_id, gl_account_id('500-205'))
        END,
        DATE(ih.added_on),
        v_amount,
        0,
        gl_item_history_remarks(ih.id),
        ih.added_by,
        NOW(),
        0
    FROM item_history AS ih
    INNER JOIN item_conversion AS ic ON ic.id = ih.unit
        AND ic.is_deleted = 0
        AND (
            ih.activity IN ('Transfer Out', 'Transfer In', 'Adjustment', 'Blindsheet')
            OR ic.conversion_type = 'clinic'
        )
    INNER JOIN mytemr_master.item AS item ON item.id = ih.item_id
        AND item.is_deleted = 0
    WHERE ih.id = p_id
        AND ih.is_deleted = 0
        AND ih.activity IN (
            'perform', 'Machine Run', 'lab supplies', 'pharmacy',
            'Transfer Out', 'Transfer In', 'Adjustment', 'Blindsheet'
        )
        AND v_amount > 0
        AND ih.activity <> 'Transfer Out'
        AND CASE
            WHEN ih.activity = 'Transfer In' THEN gl_account_id(v_inv_code)
            WHEN ih.activity = 'Adjustment' AND IFNULL(ih.adjustment_type, '') = 'returned'
                THEN gl_account_id('201-111')
            WHEN ih.activity IN ('Adjustment', 'Blindsheet') AND v_signed > 0 THEN gl_account_id(v_inv_code)
            WHEN ih.activity IN ('perform', 'Machine Run', 'lab supplies')
                THEN gl_account_id('500-205')
            ELSE COALESCE(item.account_type_id, gl_account_id('500-205'))
        END IS NOT NULL;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'item_history',
        ih.id,
        CASE
            WHEN ih.activity = 'Transfer Out' THEN gl_account_id(v_inv_code)
            WHEN ih.activity = 'Transfer In' THEN NULL
            WHEN ih.activity IN ('Adjustment', 'Blindsheet') AND v_signed > 0
                THEN COALESCE(item.account_type_id, gl_account_id('500-205'))
            ELSE gl_account_id(v_inv_code)
        END,
        DATE(ih.added_on),
        0,
        v_amount,
        gl_item_history_remarks(ih.id),
        ih.added_by,
        NOW(),
        0
    FROM item_history AS ih
    INNER JOIN item_conversion AS ic ON ic.id = ih.unit
        AND ic.is_deleted = 0
        AND (
            ih.activity IN ('Transfer Out', 'Transfer In', 'Adjustment', 'Blindsheet')
            OR ic.conversion_type = 'clinic'
        )
    INNER JOIN mytemr_master.item AS item ON item.id = ih.item_id
        AND item.is_deleted = 0
    WHERE ih.id = p_id
        AND ih.is_deleted = 0
        AND ih.activity IN (
            'perform', 'Machine Run', 'lab supplies', 'pharmacy',
            'Transfer Out', 'Transfer In', 'Adjustment', 'Blindsheet'
        )
        AND v_amount > 0
        AND ih.activity <> 'Transfer In'
        AND CASE
            WHEN ih.activity = 'Transfer Out' THEN gl_account_id(v_inv_code)
            WHEN ih.activity IN ('Adjustment', 'Blindsheet') AND v_signed > 0
                THEN COALESCE(item.account_type_id, gl_account_id('500-205'))
            ELSE gl_account_id(v_inv_code)
        END IS NOT NULL;
END$$

-- Billing collection (including unidentified) posts only on master.
DROP PROCEDURE IF EXISTS gl_post_billing_entry$$
DROP PROCEDURE IF EXISTS gl_post_billing_payment$$
DROP PROCEDURE IF EXISTS gl_post_billing_payment_deposit$$

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

DROP PROCEDURE IF EXISTS gl_post_purchase_order$$

CREATE PROCEDURE gl_post_purchase_order(IN p_id INT)
BEGIN
    DECLARE v_pr_id INT;
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM purchase_order_item WHERE purchase_order_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_clear('purchase_order_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;


    SELECT purchase_request_id INTO v_pr_id FROM purchase_order WHERE id = p_id LIMIT 1;
    IF v_pr_id IS NOT NULL THEN
        CALL gl_post_purchase_request(v_pr_id);
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_purchase_request$$

CREATE PROCEDURE gl_post_purchase_request(IN p_id INT)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_item_id INT;
    DECLARE item_cursor CURSOR FOR SELECT id FROM purchase_request_item WHERE purchase_request_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN item_cursor;
    clear_loop: LOOP
        FETCH item_cursor INTO v_item_id;
        IF v_done = 1 THEN
            LEAVE clear_loop;
        END IF;
        CALL gl_clear('purchase_request_item', v_item_id);
    END LOOP;
    CLOSE item_cursor;

END$$

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
        LEFT JOIN mytemr_master.account_type a ON a.id = e.account_type_id
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

DROP PROCEDURE IF EXISTS gl_post_apv_payment_detail$$

CREATE PROCEDURE gl_post_apv_payment_detail(IN p_id INT)
BEGIN
    CALL gl_clear('accounts_payable_voucher_payment_detail', p_id);

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'accounts_payable_voucher_payment_detail',
        apvpd.id,
        COALESCE(apvp.account_type_id, gl_account_id('100-004')),
        COALESCE(apvpd.date, DATE(apvpd.added_on)),
        0,
        apvpd.amount,
        CASE
            WHEN apv.type = 'refund' THEN CONCAT(
                COALESCE(NULLIF(txn.transaction_no, ''), CONCAT('refund #', apv.type_id)),
                ' APV pay #',
                apvpd.id
            )
            WHEN apv.type = 'receipt_note' THEN CONCAT('APV payment: ', COALESCE(NULLIF(gl_receipt_note_item_names(apv.type_id), ''), CONCAT('Receipt note #', apv.type_id)), ' #', apvpd.id)
            ELSE CONCAT('accounts_payable_voucher_payment_detail #', apvpd.id)
        END,
        apvpd.added_by,
        NOW(),
        0
    FROM accounts_payable_voucher_payment_detail AS apvpd
    INNER JOIN accounts_payable_voucher AS apv ON apv.id = apvpd.accounts_payable_voucher_id
        AND apv.is_deleted = 0
        AND apv.status IN ('approved', 'open', 'closed')
    LEFT JOIN mytemr_master.accounts_payable_voucher_payment AS apvp
        ON apvp.id = apvpd.accounts_payable_voucher_payment_id
        AND apvp.is_deleted = 0
    LEFT JOIN mytemr_master.refund AS refund
        ON refund.id = apv.type_id
        AND apv.type = 'refund'
        AND refund.is_deleted = 0
    LEFT JOIN `transaction` AS txn ON txn.id = refund.transaction_id
    WHERE apvpd.id = p_id
        AND apvpd.is_deleted = 0
        AND IFNULL(apvpd.amount, 0) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'accounts_payable_voucher_payment_detail',
        apvpd.id,
        gl_account_id('201-111'),
        COALESCE(apvpd.date, DATE(apvpd.added_on)),
        apvpd.amount,
        0,
        CASE
            WHEN apv.type = 'refund' THEN CONCAT(
                COALESCE(NULLIF(txn.transaction_no, ''), CONCAT('refund #', apv.type_id)),
                ' APV pay #',
                apvpd.id
            )
            WHEN apv.type = 'receipt_note' THEN CONCAT('Accounts payable paid: ', COALESCE(NULLIF(gl_receipt_note_item_names(apv.type_id), ''), CONCAT('Receipt note #', apv.type_id)), ' #', apvpd.id)
            ELSE CONCAT('accounts_payable_voucher_payment_detail #', apvpd.id)
        END,
        apvpd.added_by,
        NOW(),
        0
    FROM accounts_payable_voucher_payment_detail AS apvpd
    INNER JOIN accounts_payable_voucher AS apv ON apv.id = apvpd.accounts_payable_voucher_id
        AND apv.is_deleted = 0
        AND apv.status IN ('approved', 'open', 'closed')
    LEFT JOIN mytemr_master.refund AS refund
        ON refund.id = apv.type_id
        AND apv.type = 'refund'
        AND refund.is_deleted = 0
    LEFT JOIN `transaction` AS txn ON txn.id = refund.transaction_id
    WHERE apvpd.id = p_id
        AND apvpd.is_deleted = 0
        AND IFNULL(apvpd.amount, 0) > 0;
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

DROP PROCEDURE IF EXISTS gl_post_change_fund$$

CREATE PROCEDURE gl_post_change_fund(IN p_id INT)
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

DROP PROCEDURE IF EXISTS gl_post_cash_advance$$

CREATE PROCEDURE gl_post_cash_advance(IN p_id INT)
BEGIN
    -- Compatibility no-op: cash-advance accounting is posted by the APV lifecycle.
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

DROP PROCEDURE IF EXISTS gl_post_petty_cash$$

CREATE PROCEDURE gl_post_petty_cash(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$

DROP PROCEDURE IF EXISTS gl_post_journal_entry$$

CREATE PROCEDURE gl_post_journal_entry(IN p_id INT)
BEGIN
    DECLARE v_source VARCHAR(250) DEFAULT NULL;

    SELECT journal_entry.source INTO v_source
    FROM journal_entry
    WHERE journal_entry.id = p_id
    LIMIT 1;

    IF IFNULL(v_source, '') = '' THEN
        CALL gl_clear('journal_entry', p_id);

        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        )
        SELECT gl_branch_id(),
            'journal_entry',
            journal_entry.id,
            journal_entry_item.account_type_id,
            COALESCE(journal_entry.date, DATE(journal_entry.added_on)),
            IFNULL(journal_entry_item.debit, 0),
            IFNULL(journal_entry_item.credit, 0),
            COALESCE(
                NULLIF(journal_entry_item.remarks, ''),
                NULLIF(journal_entry.remarks, ''),
                CONCAT('journal_entry #', journal_entry.id)
            ),
            journal_entry_item.added_by,
            NOW(),
            0
        FROM journal_entry_item
        INNER JOIN journal_entry ON journal_entry.id = journal_entry_item.journal_entry_id
            AND journal_entry.is_deleted = 0
            AND journal_entry.status = 'approved'
        WHERE journal_entry.id = p_id
            AND journal_entry_item.is_deleted = 0
            AND journal_entry_item.account_type_id IS NOT NULL;
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_cash_deposit$$

CREATE PROCEDURE gl_post_cash_deposit(IN p_id INT)
BEGIN
    CALL gl_clear('cash_deposit', p_id);

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'cash_deposit',
        cash_deposit.id,
        gl_bank_account_id(bank.account_type_ids),
        COALESCE(cash_deposit.deposit_date, DATE(cash_deposit.added_on)),
        cash_deposit.amount,
        0,
        CONCAT('Cash deposit #', cash_deposit.id),
        cash_deposit.added_by,
        NOW(),
        0
    FROM cash_deposit
    LEFT JOIN bank ON bank.id = cash_deposit.bank_id
        AND bank.is_deleted = 0
    WHERE cash_deposit.id = p_id
        AND cash_deposit.is_deleted = 0
        AND cash_deposit.status = 'posted'
        AND IFNULL(cash_deposit.amount, 0) > 0
        AND gl_bank_account_id(bank.account_type_ids) IS NOT NULL;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT gl_branch_id(),
        'cash_deposit',
        cash_deposit.id,
        gl_account_id('100-004'),
        COALESCE(cash_deposit.deposit_date, DATE(cash_deposit.added_on)),
        0,
        cash_deposit.amount,
        CONCAT('Cash deposit from cash on hand #', cash_deposit.id),
        cash_deposit.added_by,
        NOW(),
        0
    FROM cash_deposit
    LEFT JOIN bank ON bank.id = cash_deposit.bank_id
        AND bank.is_deleted = 0
    WHERE cash_deposit.id = p_id
        AND cash_deposit.is_deleted = 0
        AND cash_deposit.status = 'posted'
        AND IFNULL(cash_deposit.amount, 0) > 0
        AND gl_bank_account_id(bank.account_type_ids) IS NOT NULL
        AND gl_account_id('100-004') IS NOT NULL;
END$$

DROP PROCEDURE IF EXISTS gl_refresh_pmm_for_ncd$$

CREATE PROCEDURE gl_refresh_pmm_for_ncd(IN p_ncd_id INT)
proc: BEGIN
    DECLARE v_pmm_id INT DEFAULT NULL;

    SELECT ncd.payment_multiple_method_id INTO v_pmm_id
    FROM non_cash_deposit AS ncd
    WHERE ncd.id = p_ncd_id
    LIMIT 1;

    IF IFNULL(v_pmm_id, 0) > 0 THEN
        CALL gl_post_csr_mop(v_pmm_id);
    END IF;
END$$

DROP PROCEDURE IF EXISTS gl_post_non_cash_deposit$$

CREATE PROCEDURE gl_post_non_cash_deposit(IN p_id INT)
proc: BEGIN
    DECLARE v_amount DECIMAL(13,4) DEFAULT 0;
    DECLARE v_pct DECIMAL(13,4) DEFAULT 0;
    DECLARE v_charges DECIMAL(13,4) DEFAULT 0;
    DECLARE v_bank DECIMAL(13,4) DEFAULT 0;
    DECLARE v_mop VARCHAR(32);
    DECLARE v_bank_id INT;
    DECLARE v_date DATE;
    DECLARE v_added_by INT;

    CALL gl_clear('non_cash_deposit', p_id);

    SELECT IFNULL(ncd.amount, 0),
        IFNULL(COALESCE(ncd.bank_service_fee_percentage, fee.percentage), 0),
        gl_ncd_mop_code(ncd.mop),
        gl_bank_account_id(bank.account_type_ids),
        COALESCE(ncd.transaction_date, DATE(ncd.added_on)),
        ncd.added_by
    INTO v_amount, v_pct, v_mop, v_bank_id, v_date, v_added_by
    FROM non_cash_deposit AS ncd
    LEFT JOIN bank_service_fee AS fee ON fee.id = ncd.bank_service_fee_id
        AND fee.is_deleted = 0
    LEFT JOIN bank ON bank.id = COALESCE(ncd.bank_id, fee.bank_id)
        AND bank.is_deleted = 0
    WHERE ncd.id = p_id
        AND ncd.is_deleted = 0
        AND ncd.status = 'posted'
    LIMIT 1;

    IF v_amount > 0 AND v_bank_id IS NOT NULL AND v_date IS NOT NULL THEN
        IF v_mop = '100-004' THEN
            SET v_charges = 0;
        ELSE
            SET v_charges = ROUND(v_amount * v_pct / 100, 2);
        END IF;

        IF v_charges > v_amount THEN
            SET v_charges = v_amount;
        END IF;
        SET v_bank = v_amount - v_charges;

        IF v_bank > 0 THEN
            CALL gl_insert(
                'non_cash_deposit', p_id, v_bank_id, v_date,
                v_bank, 0, CONCAT('Non-cash deposit #', p_id), v_added_by
            );
        END IF;

        IF v_charges > 0 THEN
            CALL gl_insert(
                'non_cash_deposit', p_id, gl_account_id('600-158'), v_date,
                v_charges, 0, CONCAT('Bank charges non-cash deposit #', p_id), v_added_by
            );
        END IF;

        CALL gl_insert(
            'non_cash_deposit', p_id, gl_account_id(v_mop), v_date,
            0, v_amount, CONCAT('Clear prior collection non-cash deposit #', p_id), v_added_by
        );
    END IF;

    CALL gl_refresh_pmm_for_ncd(p_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_general_ledger_bi$$
CREATE TRIGGER trg_gl_general_ledger_bi BEFORE INSERT ON general_ledger
FOR EACH ROW
BEGIN
    IF NEW.is_deleted = 0 AND IFNULL(NEW.source, '') <> '' AND NEW.source <> 'journal_entry' THEN
        SET NEW.remark = gl_enrich_remark(NEW.source, NEW.source_id, NEW.branch_id, NEW.remark);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_pmm_ai$$

CREATE TRIGGER trg_gl_pmm_ai AFTER INSERT ON payment_multiple_method
FOR EACH ROW
BEGIN
    CALL gl_upsert_ncd_from_pmm(NEW.id);
    CALL gl_post_csr_mop(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_pmm_au$$

CREATE TRIGGER trg_gl_pmm_au AFTER UPDATE ON payment_multiple_method
FOR EACH ROW
BEGIN
    CALL gl_upsert_ncd_from_pmm(NEW.id);
    CALL gl_post_csr_mop(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_refund_ai$$
DROP TRIGGER IF EXISTS trg_gl_refund_au$$

DROP TRIGGER IF EXISTS trg_gl_payment_ai$$

CREATE TRIGGER trg_gl_payment_ai AFTER INSERT ON payment
FOR EACH ROW
BEGIN
    CALL gl_post_csr_discount(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_payment_au$$

CREATE TRIGGER trg_gl_payment_au AFTER UPDATE ON payment
FOR EACH ROW
BEGIN
    CALL gl_post_csr_discount(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_transaction_ai$$

CREATE TRIGGER trg_gl_transaction_ai AFTER INSERT ON `transaction`
FOR EACH ROW
BEGIN
    CALL gl_post_transaction(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_transaction_au$$

CREATE TRIGGER trg_gl_transaction_au AFTER UPDATE ON `transaction`
FOR EACH ROW
BEGIN
    CALL gl_post_transaction(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_item_history_ai$$

CREATE TRIGGER trg_gl_item_history_ai AFTER INSERT ON item_history
FOR EACH ROW
BEGIN
    IF NEW.activity IN (
        'perform', 'Machine Run', 'lab supplies', 'pharmacy',
        'Transfer Out', 'Transfer In', 'Adjustment', 'Blindsheet'
    ) THEN
        CALL gl_post_item_history(NEW.id);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_item_history_au$$

CREATE TRIGGER trg_gl_item_history_au AFTER UPDATE ON item_history
FOR EACH ROW
BEGIN
    IF NEW.is_deleted = 1 THEN
        CALL gl_clear('item_history', NEW.id);
    ELSEIF NEW.activity IN (
        'perform', 'Machine Run', 'lab supplies', 'pharmacy',
        'Transfer Out', 'Transfer In', 'Adjustment', 'Blindsheet'
    ) THEN
        CALL gl_post_item_history(NEW.id);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_entry_ai$$
DROP TRIGGER IF EXISTS trg_gl_billing_entry_au$$
DROP TRIGGER IF EXISTS trg_gl_billing_payment_ai$$
DROP TRIGGER IF EXISTS trg_gl_billing_payment_au$$

DROP TRIGGER IF EXISTS trg_gl_receipt_note_au$$

CREATE TRIGGER trg_gl_receipt_note_au AFTER UPDATE ON receipt_note
FOR EACH ROW
BEGIN
    CALL gl_post_receipt_note(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_receipt_note_ai$$

CREATE TRIGGER trg_gl_receipt_note_ai AFTER INSERT ON receipt_note
FOR EACH ROW
BEGIN
    CALL gl_post_receipt_note(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_receipt_note_item_ai$$

CREATE TRIGGER trg_gl_receipt_note_item_ai AFTER INSERT ON receipt_note_item
FOR EACH ROW
BEGIN
    CALL gl_post_receipt_note(NEW.receipt_note_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_receipt_note_item_au$$

CREATE TRIGGER trg_gl_receipt_note_item_au AFTER UPDATE ON receipt_note_item
FOR EACH ROW
BEGIN
    CALL gl_post_receipt_note(NEW.receipt_note_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_purchase_order_au$$

CREATE TRIGGER trg_gl_purchase_order_au AFTER UPDATE ON purchase_order
FOR EACH ROW
BEGIN
    CALL gl_post_purchase_order(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_purchase_order_ai$$

CREATE TRIGGER trg_gl_purchase_order_ai AFTER INSERT ON purchase_order
FOR EACH ROW
BEGIN
    CALL gl_post_purchase_order(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_purchase_request_au$$

CREATE TRIGGER trg_gl_purchase_request_au AFTER UPDATE ON purchase_request
FOR EACH ROW
BEGIN
    CALL gl_post_purchase_request(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_purchase_request_ai$$

CREATE TRIGGER trg_gl_purchase_request_ai AFTER INSERT ON purchase_request
FOR EACH ROW
BEGIN
    CALL gl_post_purchase_request(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_apv_au$$

CREATE TRIGGER trg_gl_apv_au AFTER UPDATE ON accounts_payable_voucher
FOR EACH ROW
BEGIN
    CALL gl_post_apv(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_apv_ai$$

CREATE TRIGGER trg_gl_apv_ai AFTER INSERT ON accounts_payable_voucher
FOR EACH ROW
BEGIN
    CALL gl_post_apv(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_apvpd_ai$$

CREATE TRIGGER trg_gl_apvpd_ai AFTER INSERT ON accounts_payable_voucher_payment_detail
FOR EACH ROW
BEGIN
    CALL gl_post_apv_payment_detail(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_apvpd_au$$

CREATE TRIGGER trg_gl_apvpd_au AFTER UPDATE ON accounts_payable_voucher_payment_detail
FOR EACH ROW
BEGIN
    CALL gl_post_apv_payment_detail(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_direct_payment_au$$

CREATE TRIGGER trg_gl_direct_payment_au AFTER UPDATE ON direct_payment
FOR EACH ROW
BEGIN
    CALL gl_post_direct_payment(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_direct_payment_ai$$

CREATE TRIGGER trg_gl_direct_payment_ai AFTER INSERT ON direct_payment
FOR EACH ROW
BEGIN
    CALL gl_post_direct_payment(NEW.id);
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

DROP TRIGGER IF EXISTS trg_gl_direct_payment_entry_ai$$


DROP TRIGGER IF EXISTS trg_gl_direct_payment_entry_au$$


DROP TRIGGER IF EXISTS trg_gl_direct_payment_entry_ad$$


DROP TRIGGER IF EXISTS trg_gl_change_fund_ad$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_ad$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_ad$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_ad$$
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

DROP TRIGGER IF EXISTS trg_gl_change_fund_au$$

DROP TRIGGER IF EXISTS trg_gl_change_fund_ai$$

DROP TRIGGER IF EXISTS trg_gl_liquidation_au$$

DROP TRIGGER IF EXISTS trg_gl_liquidation_ai$$

DROP TRIGGER IF EXISTS trg_gl_cash_advance_au$$

DROP TRIGGER IF EXISTS trg_gl_cash_advance_ai$$

DROP TRIGGER IF EXISTS trg_gl_reimbursement_au$$

DROP TRIGGER IF EXISTS trg_gl_reimbursement_ai$$

DROP TRIGGER IF EXISTS trg_gl_replenishment_au$$

DROP TRIGGER IF EXISTS trg_gl_replenishment_ai$$

DROP TRIGGER IF EXISTS trg_gl_petty_cash_au$$

DROP TRIGGER IF EXISTS trg_gl_petty_cash_ai$$

-- Header AI/AU would UPDATE journal_entry via gl_clear (#1442). Post from items only.
DROP TRIGGER IF EXISTS trg_gl_journal_entry_au$$
DROP TRIGGER IF EXISTS trg_gl_journal_entry_ai$$

DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_ai$$

CREATE TRIGGER trg_gl_journal_entry_item_ai AFTER INSERT ON journal_entry_item
FOR EACH ROW
BEGIN
    IF (
        SELECT IFNULL(journal_entry.source, '')
        FROM journal_entry
        WHERE journal_entry.id = NEW.journal_entry_id
        LIMIT 1
    ) = '' THEN
        CALL gl_post_journal_entry(NEW.journal_entry_id);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_au$$

CREATE TRIGGER trg_gl_journal_entry_item_au AFTER UPDATE ON journal_entry_item
FOR EACH ROW
BEGIN
    IF (
        SELECT IFNULL(journal_entry.source, '')
        FROM journal_entry
        WHERE journal_entry.id = NEW.journal_entry_id
        LIMIT 1
    ) = '' THEN
        CALL gl_post_journal_entry(NEW.journal_entry_id);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_general_ledger_ai$$

CREATE TRIGGER trg_gl_general_ledger_ai AFTER INSERT ON general_ledger
FOR EACH ROW
BEGIN
    IF NEW.is_deleted = 0
        AND IFNULL(NEW.source, '') <> ''
        AND NEW.source <> 'journal_entry'
    THEN
        CALL gl_je_from_gl(
            NEW.source,
            NEW.source_id,
            NEW.account_type_id,
            NEW.date,
            NEW.debit,
            NEW.credit,
            NEW.remark,
            NEW.added_by
        );
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_payment_deposit_ai$$
DROP TRIGGER IF EXISTS trg_gl_billing_payment_deposit_au$$

DROP TRIGGER IF EXISTS trg_gl_cash_deposit_ai$$

CREATE TRIGGER trg_gl_cash_deposit_ai AFTER INSERT ON cash_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_cash_deposit(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_cash_deposit_au$$

CREATE TRIGGER trg_gl_cash_deposit_au AFTER UPDATE ON cash_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_cash_deposit(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_non_cash_deposit_ai$$

CREATE TRIGGER trg_gl_non_cash_deposit_ai AFTER INSERT ON non_cash_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_non_cash_deposit(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_non_cash_deposit_au$$

CREATE TRIGGER trg_gl_non_cash_deposit_au AFTER UPDATE ON non_cash_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_non_cash_deposit(NEW.id);
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
