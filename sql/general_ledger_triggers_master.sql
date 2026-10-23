-- Billing / collection / deposit / credit-debit memo GL triggers for mytemr_master only.
-- Do not run this file on clinic DBs.
-- Render with scripts/prepare_gl_remarks_sql.py before any installation.
-- Recreate this file on mytemr_master after any JE/GL trigger change.
-- This file drops leftover trg_gl_journal_entry_* and trg_gl_general_ledger_ai.
-- Installation changes future postings only; do not run a backfill for remark enrichment.
-- After sql/migrations/journal_entry_source.sql, recreate this file so billing /
-- memo / refund-kick GL rows also write one approved journal_entry per pair.
-- Master has no JE→GL triggers (beginning JEs keep source NULL).
-- Do not attach trg_gl_journal_entry_* or trg_gl_general_ledger_ai.
-- JEs are created once in gl_je_rebuild after all GL lines (avoids #1442).

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

CREATE TABLE IF NOT EXISTS general_ledger (
    id int(11) NOT NULL AUTO_INCREMENT,
    branch_id int(11) DEFAULT NULL,
    source varchar(250) NOT NULL COMMENT 'parent/child table',
    source_id int(11) NOT NULL,
    account_type_id int(11) DEFAULT NULL,
    date date NOT NULL,
    debit decimal(13,4) DEFAULT 0.0000,
    credit decimal(13,4) DEFAULT 0.0000,
    remark longtext DEFAULT NULL,
    added_by int(11) DEFAULT NULL,
    added_on datetime DEFAULT NULL,
    updated_by int(11) DEFAULT NULL,
    updated_on datetime DEFAULT NULL,
    is_deleted tinyint(1) DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP INDEX IF EXISTS idx_gl_source_id ON general_ledger;
CREATE INDEX idx_gl_source_id ON general_ledger (source, source_id, is_deleted);

DROP INDEX IF EXISTS idx_gl_date_account ON general_ledger;
CREATE INDEX idx_gl_date_account ON general_ledger (date, is_deleted, account_type_id);

DROP INDEX IF EXISTS idx_gl_branch_source ON general_ledger;
CREATE INDEX idx_gl_branch_source ON general_ledger (branch_id, source, source_id, is_deleted);

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
    FROM account_type
    WHERE account_type.code = p_code
        AND account_type.parent_id IS NULL
        AND account_type.is_deleted = 0
    LIMIT 1;
    RETURN v_id;
END$$

DROP FUNCTION IF EXISTS gl_billing_payer_label$$

CREATE FUNCTION gl_billing_payer_label(p_company_id INT)
RETURNS VARCHAR(32)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_name VARCHAR(255) DEFAULT '';
    DECLARE v_type VARCHAR(100) DEFAULT '';
    DECLARE v_is_hmo INT DEFAULT 0;

    IF IFNULL(p_company_id, 0) <= 0 THEN
        RETURN 'Patient';
    END IF;

    SELECT IFNULL(company.name, ''),
        IFNULL(company.type, ''),
        IFNULL(company.is_hmo, 0)
    INTO v_name, v_type, v_is_hmo
    FROM company
    WHERE company.id = p_company_id
        AND company.is_deleted = 0
    LIMIT 1;

    IF UPPER(v_name) LIKE '%YAKAP%'
        OR UPPER(v_type) LIKE '%YAKAP%'
        OR LOWER(v_type) = 'yakap'
    THEN
        RETURN 'Yakap';
    END IF;
    IF v_is_hmo = 1 OR LOWER(v_type) = 'hmo' THEN
        RETURN 'HMO';
    END IF;
    RETURN 'Corporate';
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

DROP PROCEDURE IF EXISTS gl_clear$$

CREATE PROCEDURE gl_clear(IN p_source VARCHAR(250), IN p_source_id INT)
BEGIN
    UPDATE general_ledger
    SET is_deleted = 1,
        updated_on = NOW()
    WHERE source = p_source
        AND source_id = p_source_id
        AND is_deleted = 0;
END$$

DROP PROCEDURE IF EXISTS gl_insert$$

CREATE PROCEDURE gl_insert(
    IN p_branch_id INT,
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
    IF IFNULL(p_branch_id, 0) > 0
        AND p_account_type_id IS NOT NULL
        AND p_date IS NOT NULL
        AND (IFNULL(p_debit, 0) <> 0 OR IFNULL(p_credit, 0) <> 0)
    THEN
        INSERT INTO general_ledger (
            branch_id, source, source_id, account_type_id, date,
            debit, credit, remark, added_by, added_on, is_deleted
        ) VALUES (
            p_branch_id, p_source, p_source_id, p_account_type_id, p_date,
            IFNULL(p_debit, 0), IFNULL(p_credit, 0), p_remark, p_added_by, NOW(), 0
        );
    END IF;
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

DROP FUNCTION IF EXISTS gl_billing_transaction_no$$

CREATE FUNCTION gl_billing_transaction_no(p_branch_id INT, p_transaction_id INT)
RETURNS VARCHAR(255)
READS SQL DATA
BEGIN
    DECLARE v_transaction_no VARCHAR(255) DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_transaction_no = NULL;
    IF IFNULL(p_transaction_id, 0) > 0 THEN
        CASE p_branch_id
            WHEN 1 THEN
                SELECT NULLIF(TRIM(CAST(transaction_no AS CHAR)), '') INTO v_transaction_no
                FROM mytemr_diagnostic_mobile.`transaction` WHERE id = p_transaction_id LIMIT 1;
            WHEN 2 THEN
                SELECT NULLIF(TRIM(CAST(transaction_no AS CHAR)), '') INTO v_transaction_no
                FROM mytemr_diagnostic.`transaction` WHERE id = p_transaction_id LIMIT 1;
            WHEN 3 THEN
                SELECT NULLIF(TRIM(CAST(transaction_no AS CHAR)), '') INTO v_transaction_no
                FROM mytemr_diagnostic_mactan.`transaction` WHERE id = p_transaction_id LIMIT 1;
            WHEN 4 THEN
                SELECT NULLIF(TRIM(CAST(transaction_no AS CHAR)), '') INTO v_transaction_no
                FROM mytemr_diagnostic_conso.`transaction` WHERE id = p_transaction_id LIMIT 1;
            WHEN 7 THEN
                SELECT NULLIF(TRIM(CAST(transaction_no AS CHAR)), '') INTO v_transaction_no
                FROM mytemr_diagnostic_mantlewood.`transaction` WHERE id = p_transaction_id LIMIT 1;
            ELSE SET v_transaction_no = NULL;
        END CASE;
    END IF;
    RETURN v_transaction_no;
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
-- END SHARED REMARK HELPERS

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

DROP PROCEDURE IF EXISTS gl_post_billing_entry$$

CREATE PROCEDURE gl_post_billing_entry(IN p_id INT)
BEGIN
    DECLARE v_reference LONGTEXT DEFAULT NULL;
    SELECT gl_enrich_remark('billing_entry', be.id, be.branch_id, CONCAT('Billing #', be.billing_id, ' / entry #', be.id))
    INTO v_reference FROM billing_entry AS be WHERE be.id = p_id;

    IF EXISTS (
        SELECT 1 FROM billing_entry AS be INNER JOIN billing AS b ON b.id = be.billing_id
        WHERE be.id = p_id AND be.is_deleted = 0 AND b.is_deleted = 0
            AND b.status IN ('pending', 'approved', 'open_bill', 'closed_bill')
            AND be.status IN ('pending', 'open_bill', 'closed_bill')
            AND IFNULL(be.branch_id, 0) >= 0 AND IFNULL(be.amount, 0) > 0
    ) AND (gl_account_id('102-113') IS NULL OR gl_account_id('102-113B') IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing billing AR account mapping';
    END IF;
    CALL gl_clear_billing_movement('billing_entry', p_id);

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT IFNULL(billing_entry.branch_id, 0),
        'billing_entry',
        billing_entry.id,
        gl_account_id('102-113'),
        DATE(COALESCE(NULLIF(billing.added_on, '0000-00-00 00:00:00'), billing_entry.added_on)),
        billing_entry.amount,
        0,
        CONCAT(gl_billing_payer_label(billing.charge_to), ' AR billed / ', v_reference),
        billing_entry.added_by,
        NOW(),
        0
    FROM billing_entry
    INNER JOIN billing ON billing.id = billing_entry.billing_id
        AND billing.is_deleted = 0
        AND billing.status IN ('pending', 'approved', 'open_bill', 'closed_bill')
    WHERE billing_entry.id = p_id
        AND billing_entry.is_deleted = 0
        AND billing_entry.status IN ('pending', 'open_bill', 'closed_bill')
        AND IFNULL(billing_entry.branch_id, 0) >= 0
        AND IFNULL(billing_entry.amount, 0) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT IFNULL(billing_entry.branch_id, 0),
        'billing_entry',
        billing_entry.id,
        gl_account_id('102-113B'),
        DATE(COALESCE(NULLIF(billing.added_on, '0000-00-00 00:00:00'), billing_entry.added_on)),
        0,
        billing_entry.amount,
        CONCAT(gl_billing_payer_label(billing.charge_to), ' AR unbilled reclass / ', v_reference),
        billing_entry.added_by,
        NOW(),
        0
    FROM billing_entry
    INNER JOIN billing ON billing.id = billing_entry.billing_id
        AND billing.is_deleted = 0
        AND billing.status IN ('pending', 'approved', 'open_bill', 'closed_bill')
    WHERE billing_entry.id = p_id
        AND billing_entry.is_deleted = 0
        AND billing_entry.status IN ('pending', 'open_bill', 'closed_bill')
        AND IFNULL(billing_entry.branch_id, 0) >= 0
        AND IFNULL(billing_entry.amount, 0) > 0;

    CALL gl_je_rebuild('billing_entry', p_id);
    UPDATE journal_entry SET remarks = v_reference
    WHERE source = 'billing_entry' AND source_id = p_id AND is_deleted = 0;
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

DROP PROCEDURE IF EXISTS gl_post_check_deposit$$

CREATE PROCEDURE gl_post_check_deposit(IN p_id INT)
BEGIN
    CALL gl_clear('check_deposit', p_id);

    -- Identified checks are allocated across the billing-entry branches.
    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT share.branch_id, 'check_deposit', cd.id, cd.account_type_id,
        DATE(cd.posted_on), share.branch_amount, 0,
        CONCAT('Check deposit #', cd.id), bp.added_by, NOW(), 0
    FROM check_deposit AS cd
    INNER JOIN billing_payment AS bp ON bp.id = cd.billing_payment_id
        AND bp.is_deleted = 0 AND LOWER(IFNULL(bp.mop, '')) = 'check'
    INNER JOIN (
        SELECT amounts.check_deposit_id, amounts.branch_id,
            amounts.amount * amounts.branch_amount / NULLIF(amounts.total_amount, 0) AS branch_amount
        FROM (
            SELECT cd2.id AS check_deposit_id, cd2.amount, be.branch_id,
                SUM(IFNULL(bpe.amount, 0)) AS branch_amount,
                SUM(SUM(IFNULL(bpe.amount, 0))) OVER (PARTITION BY cd2.id) AS total_amount
            FROM check_deposit AS cd2
            INNER JOIN billing_payment_entry AS bpe ON bpe.billing_payment_id = cd2.billing_payment_id
                AND bpe.is_deleted = 0
            INNER JOIN billing_entry AS be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
            WHERE cd2.id = p_id AND IFNULL(be.branch_id, 0) > 0
            GROUP BY cd2.id, cd2.amount, be.branch_id
        ) AS amounts
    ) AS share ON share.check_deposit_id = cd.id
    WHERE cd.id = p_id AND cd.is_deleted = 0 AND cd.status = 'posted'
        AND cd.account_type_id IS NOT NULL AND DATE(cd.posted_on) IS NOT NULL
        AND IFNULL(share.branch_amount, 0) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT share.branch_id, 'check_deposit', cd.id, bp.account_type_id,
        DATE(cd.posted_on), 0, share.branch_amount,
        CONCAT('Check deposit from check-on-hand #', cd.id), bp.added_by, NOW(), 0
    FROM check_deposit AS cd
    INNER JOIN billing_payment AS bp ON bp.id = cd.billing_payment_id
        AND bp.is_deleted = 0 AND LOWER(IFNULL(bp.mop, '')) = 'check'
        AND bp.account_type_id IS NOT NULL
    INNER JOIN (
        SELECT amounts.check_deposit_id, amounts.branch_id,
            amounts.amount * amounts.branch_amount / NULLIF(amounts.total_amount, 0) AS branch_amount
        FROM (
            SELECT cd2.id AS check_deposit_id, cd2.amount, be.branch_id,
                SUM(IFNULL(bpe.amount, 0)) AS branch_amount,
                SUM(SUM(IFNULL(bpe.amount, 0))) OVER (PARTITION BY cd2.id) AS total_amount
            FROM check_deposit AS cd2
            INNER JOIN billing_payment_entry AS bpe ON bpe.billing_payment_id = cd2.billing_payment_id
                AND bpe.is_deleted = 0
            INNER JOIN billing_entry AS be ON be.id = bpe.billing_entry_id AND be.is_deleted = 0
            WHERE cd2.id = p_id AND IFNULL(be.branch_id, 0) > 0
            GROUP BY cd2.id, cd2.amount, be.branch_id
        ) AS amounts
    ) AS share ON share.check_deposit_id = cd.id
    WHERE cd.id = p_id AND cd.is_deleted = 0 AND cd.status = 'posted'
        AND cd.account_type_id IS NOT NULL AND DATE(cd.posted_on) IS NOT NULL
        AND IFNULL(share.branch_amount, 0) > 0;

    -- Unidentified checks have no billing entries or branch allocation yet.
    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0, 'check_deposit', cd.id, cd.account_type_id, DATE(cd.posted_on),
        cd.amount, 0, CONCAT('Check deposit #', cd.id), bp.added_by, NOW(), 0
    FROM check_deposit AS cd
    INNER JOIN billing_payment AS bp ON bp.id = cd.billing_payment_id
        AND bp.is_deleted = 0 AND LOWER(IFNULL(bp.mop, '')) = 'check'
    WHERE cd.id = p_id AND cd.is_deleted = 0 AND cd.status = 'posted'
        AND cd.account_type_id IS NOT NULL AND DATE(cd.posted_on) IS NOT NULL
        AND IFNULL(bp.is_unidentified, 0) = 1 AND IFNULL(cd.amount, 0) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT 0, 'check_deposit', cd.id, bp.account_type_id, DATE(cd.posted_on),
        0, cd.amount, CONCAT('Check deposit from check-on-hand #', cd.id), bp.added_by, NOW(), 0
    FROM check_deposit AS cd
    INNER JOIN billing_payment AS bp ON bp.id = cd.billing_payment_id
        AND bp.is_deleted = 0 AND LOWER(IFNULL(bp.mop, '')) = 'check'
        AND bp.account_type_id IS NOT NULL
    WHERE cd.id = p_id AND cd.is_deleted = 0 AND cd.status = 'posted'
        AND cd.account_type_id IS NOT NULL AND DATE(cd.posted_on) IS NOT NULL
        AND IFNULL(bp.is_unidentified, 0) = 1 AND IFNULL(cd.amount, 0) > 0;

    -- Keep the source journal entry idempotent if this update trigger fires again.
    UPDATE journal_entry_item AS jei
    INNER JOIN journal_entry AS je ON je.id = jei.journal_entry_id
    SET jei.is_deleted = 1, jei.updated_on = NOW()
    WHERE je.source = 'check_deposit' AND je.source_id = p_id
        AND je.is_deleted = 0 AND jei.is_deleted = 0;
    UPDATE journal_entry SET is_deleted = 1, updated_on = NOW()
    WHERE source = 'check_deposit' AND source_id = p_id AND is_deleted = 0;

    CALL gl_je_rebuild('check_deposit', p_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_entry_ai$$

CREATE TRIGGER trg_gl_billing_entry_ai AFTER INSERT ON billing_entry
FOR EACH ROW
BEGIN
    CALL gl_post_billing_entry(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_entry_au$$

CREATE TRIGGER trg_gl_billing_entry_au AFTER UPDATE ON billing_entry
FOR EACH ROW
BEGIN
    CALL gl_post_billing_entry(NEW.id);
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

DROP TRIGGER IF EXISTS trg_gl_billing_payment_deposit_ai$$

CREATE TRIGGER trg_gl_billing_payment_deposit_ai AFTER INSERT ON billing_payment_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_billing_payment_deposit(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_payment_deposit_au$$

CREATE TRIGGER trg_gl_billing_payment_deposit_au AFTER UPDATE ON billing_payment_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_billing_payment_deposit(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_check_deposit_ai$$

CREATE TRIGGER trg_gl_check_deposit_ai AFTER INSERT ON check_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_check_deposit(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_check_deposit_au$$

CREATE TRIGGER trg_gl_check_deposit_au AFTER UPDATE ON check_deposit
FOR EACH ROW
BEGIN
    CALL gl_post_check_deposit(NEW.id);
END$$

DROP PROCEDURE IF EXISTS gl_post_billing$$

CREATE PROCEDURE gl_post_billing(IN p_id INT)
BEGIN
    DECLARE v_done INT DEFAULT 0;
    DECLARE v_entry_id INT;
    DECLARE cur_entry CURSOR FOR
        SELECT billing_entry.id FROM billing_entry WHERE billing_entry.billing_id = p_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur_entry;
    entry_loop: LOOP
        FETCH cur_entry INTO v_entry_id;
        IF v_done = 1 THEN
            LEAVE entry_loop;
        END IF;
        CALL gl_post_billing_entry(v_entry_id);
    END LOOP;
    CLOSE cur_entry;
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_ai$$

CREATE TRIGGER trg_gl_billing_ai AFTER INSERT ON billing
FOR EACH ROW
BEGIN
    CALL gl_post_billing(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_billing_au$$

CREATE TRIGGER trg_gl_billing_au AFTER UPDATE ON billing
FOR EACH ROW
BEGIN
    CALL gl_post_billing(NEW.id);
END$$

DROP PROCEDURE IF EXISTS gl_post_credit_memo$$

CREATE PROCEDURE gl_post_credit_memo(IN p_id INT)
BEGIN
    CALL gl_clear('credit_memo', p_id);

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT credit_memo.branch_id,
        'credit_memo',
        credit_memo.id,
        credit_memo.debit_account_type_id,
        COALESCE(credit_memo.date_issued, DATE(credit_memo.added_on)),
        credit_memo.amount,
        0,
        CONCAT('Credit memo #', IFNULL(credit_memo.control_no, credit_memo.id)),
        credit_memo.added_by,
        NOW(),
        0
    FROM credit_memo
    WHERE credit_memo.id = p_id
        AND credit_memo.is_deleted = 0
        AND credit_memo.status = 'approved'
        AND IFNULL(credit_memo.amount, 0) > 0
        AND credit_memo.debit_account_type_id IS NOT NULL
        AND IFNULL(credit_memo.branch_id, 0) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT credit_memo.branch_id,
        'credit_memo',
        credit_memo.id,
        COALESCE(credit_memo.credit_account_type_id, gl_account_id('102-113')),
        COALESCE(credit_memo.date_issued, DATE(credit_memo.added_on)),
        0,
        credit_memo.amount,
        CONCAT('Credit memo AR #', IFNULL(credit_memo.control_no, credit_memo.id)),
        credit_memo.added_by,
        NOW(),
        0
    FROM credit_memo
    WHERE credit_memo.id = p_id
        AND credit_memo.is_deleted = 0
        AND credit_memo.status = 'approved'
        AND IFNULL(credit_memo.amount, 0) > 0
        AND COALESCE(credit_memo.credit_account_type_id, gl_account_id('102-113')) IS NOT NULL
        AND IFNULL(credit_memo.branch_id, 0) > 0;

    CALL gl_je_rebuild('credit_memo', p_id);
END$$

DROP PROCEDURE IF EXISTS gl_post_debit_memo$$

CREATE PROCEDURE gl_post_debit_memo(IN p_id INT)
BEGIN
    CALL gl_clear('debit_memo', p_id);

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT debit_memo.branch_id,
        'debit_memo',
        debit_memo.id,
        COALESCE(debit_memo.debit_account_type_id, gl_account_id('102-113')),
        COALESCE(debit_memo.date_issued, DATE(debit_memo.added_on)),
        debit_memo.amount,
        0,
        CONCAT('Debit memo AR #', IFNULL(debit_memo.control_no, debit_memo.id)),
        debit_memo.added_by,
        NOW(),
        0
    FROM debit_memo
    WHERE debit_memo.id = p_id
        AND debit_memo.is_deleted = 0
        AND debit_memo.status = 'approved'
        AND IFNULL(debit_memo.amount, 0) > 0
        AND COALESCE(debit_memo.debit_account_type_id, gl_account_id('102-113')) IS NOT NULL
        AND IFNULL(debit_memo.branch_id, 0) > 0;

    INSERT INTO general_ledger (
        branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted
    )
    SELECT debit_memo.branch_id,
        'debit_memo',
        debit_memo.id,
        debit_memo.credit_account_type_id,
        COALESCE(debit_memo.date_issued, DATE(debit_memo.added_on)),
        0,
        debit_memo.amount,
        CONCAT('Debit memo #', IFNULL(debit_memo.control_no, debit_memo.id)),
        debit_memo.added_by,
        NOW(),
        0
    FROM debit_memo
    WHERE debit_memo.id = p_id
        AND debit_memo.is_deleted = 0
        AND debit_memo.status = 'approved'
        AND IFNULL(debit_memo.amount, 0) > 0
        AND debit_memo.credit_account_type_id IS NOT NULL
        AND IFNULL(debit_memo.branch_id, 0) > 0;

    CALL gl_je_rebuild('debit_memo', p_id);
END$$

DROP TRIGGER IF EXISTS trg_gl_general_ledger_bi$$
CREATE TRIGGER trg_gl_general_ledger_bi BEFORE INSERT ON general_ledger
FOR EACH ROW
BEGIN
    IF NEW.is_deleted = 0 AND IFNULL(NEW.source, '') <> '' AND NEW.source <> 'journal_entry' THEN
        SET NEW.remark = gl_enrich_remark(NEW.source, NEW.source_id, NEW.branch_id, NEW.remark);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_gl_credit_memo_ai$$

CREATE TRIGGER trg_gl_credit_memo_ai AFTER INSERT ON credit_memo
FOR EACH ROW
BEGIN
    CALL gl_post_credit_memo(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_credit_memo_au$$

CREATE TRIGGER trg_gl_credit_memo_au AFTER UPDATE ON credit_memo
FOR EACH ROW
BEGIN
    CALL gl_post_credit_memo(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_debit_memo_ai$$

CREATE TRIGGER trg_gl_debit_memo_ai AFTER INSERT ON debit_memo
FOR EACH ROW
BEGIN
    CALL gl_post_debit_memo(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_debit_memo_au$$

CREATE TRIGGER trg_gl_debit_memo_au AFTER UPDATE ON debit_memo
FOR EACH ROW
BEGIN
    CALL gl_post_debit_memo(NEW.id);
END$$

DROP FUNCTION IF EXISTS gl_clinic_schema$$

CREATE FUNCTION gl_clinic_schema(p_branch_id INT)
RETURNS VARCHAR(64)
DETERMINISTIC
NO SQL
BEGIN
    CASE p_branch_id
        WHEN 1 THEN RETURN 'mytemr_diagnostic_mobile';
        WHEN 2 THEN RETURN 'mytemr_diagnostic';
        WHEN 3 THEN RETURN 'mytemr_diagnostic_mactan';
        WHEN 4 THEN RETURN 'mytemr_diagnostic_conso';
        WHEN 7 THEN RETURN 'mytemr_diagnostic_mantlewood';
        ELSE RETURN NULL;
    END CASE;
END$$

DROP PROCEDURE IF EXISTS gl_kick_clinic_refund$$

CREATE PROCEDURE gl_kick_clinic_refund(IN p_id INT)
BEGIN
    DECLARE v_branch INT;

    SELECT refund.branch_id INTO v_branch
    FROM refund
    WHERE refund.id = p_id
    LIMIT 1;

    CASE v_branch
        WHEN 1 THEN CALL mytemr_diagnostic_mobile.gl_post_refund(p_id);
        WHEN 2 THEN CALL mytemr_diagnostic.gl_post_refund(p_id);
        WHEN 3 THEN CALL mytemr_diagnostic_mactan.gl_post_refund(p_id);
        WHEN 4 THEN CALL mytemr_diagnostic_conso.gl_post_refund(p_id);
        WHEN 7 THEN CALL mytemr_diagnostic_mantlewood.gl_post_refund(p_id);
        ELSE BEGIN END;
    END CASE;
END$$

DROP TRIGGER IF EXISTS trg_gl_refund_ai$$

CREATE TRIGGER trg_gl_refund_ai AFTER INSERT ON refund
FOR EACH ROW
BEGIN
    CALL gl_kick_clinic_refund(NEW.id);
END$$

DROP TRIGGER IF EXISTS trg_gl_refund_au$$

CREATE TRIGGER trg_gl_refund_au AFTER UPDATE ON refund
FOR EACH ROW
BEGIN
    CALL gl_kick_clinic_refund(NEW.id);
END$$

DROP PROCEDURE IF EXISTS gl_bf_billing_drain$$

CREATE PROCEDURE gl_bf_billing_drain(IN p_kind VARCHAR(64))
BEGIN
    DECLARE v_id INT;
    DECLARE v_cnt INT DEFAULT 0;

    SELECT COUNT(*) INTO v_cnt FROM gl_bf_ids;
    WHILE v_cnt > 0 DO
        SELECT id INTO v_id FROM gl_bf_ids LIMIT 1;
        CASE p_kind
            WHEN 'billing_entry' THEN CALL gl_post_billing_entry(v_id);
            WHEN 'billing_payment' THEN CALL gl_post_billing_payment(v_id);
            WHEN 'billing_payment_deposit' THEN CALL gl_post_billing_payment_deposit(v_id);
            WHEN 'check_deposit' THEN CALL gl_post_check_deposit(v_id);
            WHEN 'credit_memo' THEN CALL gl_post_credit_memo(v_id);
            WHEN 'debit_memo' THEN CALL gl_post_debit_memo(v_id);
            WHEN 'refund' THEN CALL gl_kick_clinic_refund(v_id);
            ELSE SET v_id = v_id;
        END CASE;
        DELETE FROM gl_bf_ids WHERE id = v_id;
        SET v_cnt = v_cnt - 1;
    END WHILE;
END$$

DROP PROCEDURE IF EXISTS gl_backfill_billing$$

CREATE PROCEDURE gl_backfill_billing()
BEGIN
    DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
    CREATE TEMPORARY TABLE gl_bf_ids (
        id INT NOT NULL,
        PRIMARY KEY (id)
    );

    INSERT INTO gl_bf_ids SELECT id FROM billing_entry WHERE is_deleted = 0;
    CALL gl_bf_billing_drain('billing_entry');

    INSERT INTO gl_bf_ids SELECT id FROM billing_payment WHERE is_deleted = 0;
    CALL gl_bf_billing_drain('billing_payment');

    INSERT INTO gl_bf_ids SELECT id FROM billing_payment_deposit WHERE is_deleted = 0;
    CALL gl_bf_billing_drain('billing_payment_deposit');

    INSERT INTO gl_bf_ids SELECT id FROM check_deposit WHERE is_deleted = 0;
    CALL gl_bf_billing_drain('check_deposit');

    INSERT INTO gl_bf_ids SELECT id FROM credit_memo WHERE is_deleted = 0;
    CALL gl_bf_billing_drain('credit_memo');

    INSERT INTO gl_bf_ids SELECT id FROM debit_memo WHERE is_deleted = 0;
    CALL gl_bf_billing_drain('debit_memo');

    INSERT INTO gl_bf_ids SELECT id FROM refund WHERE is_deleted = 0;
    CALL gl_bf_billing_drain('refund');

    DROP TEMPORARY TABLE IF EXISTS gl_bf_ids;
END$$

-- Master has no JE→GL triggers (beginning JEs keep source NULL).
-- System JEs are created in gl_je_rebuild after all GL lines.
-- Do not attach trg_gl_journal_entry_* or trg_gl_general_ledger_ai (#1442).

DROP TRIGGER IF EXISTS trg_gl_general_ledger_ai$$
DROP TRIGGER IF EXISTS trg_gl_journal_entry_ai$$
DROP TRIGGER IF EXISTS trg_gl_journal_entry_au$$
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_ai$$
DROP TRIGGER IF EXISTS trg_gl_journal_entry_item_au$$

DELIMITER ;

-- BEGIN SHARED FUND ACCOUNTING (generated by sql/shared_funds/build.py)
-- Selected MASTER schema only. Additive, no seeds/backfill.
ALTER TABLE accounts_payable_voucher ADD COLUMN IF NOT EXISTS cost_center_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_apv_shared_center ON accounts_payable_voucher(cost_center_id,is_deleted,id);
CREATE TABLE IF NOT EXISTS accounts_payable_voucher_entry (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    accounts_payable_voucher_id INT NOT NULL,
    account_type_id INT NOT NULL,
    debit DECIMAL(13,4) DEFAULT 0.0000,
    credit DECIMAL(13,4) DEFAULT 0.0000,
    remarks VARCHAR(250) NULL,
    added_by INT NOT NULL,
    added_on DATETIME NOT NULL,
    updated_by INT NULL,
    updated_on DATETIME NULL,
    is_deleted TINYINT DEFAULT 0,
    KEY idx_apv_entry_parent (accounts_payable_voucher_id, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts_payable_voucher_amortization_entry (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    accounts_payable_voucher_id INT NOT NULL,
    expense_account_type_id INT NOT NULL,
    prepaid_account_type_id INT NOT NULL,
    amount DECIMAL(13,4) NOT NULL,
    added_by INT NOT NULL,
    added_on DATETIME NOT NULL,
    updated_by INT NULL,
    updated_on DATETIME NULL,
    is_deleted TINYINT NOT NULL DEFAULT 0,
    KEY idx_apv_amortization_parent (accounts_payable_voucher_id, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Execute under the release write pause. Reruns must never advance the cutoffs.
CREATE TABLE IF NOT EXISTS apv_accounting_cutover (
    id TINYINT NOT NULL PRIMARY KEY,
    legacy_max_apv_id INT NOT NULL,
    legacy_max_direct_payment_id INT NOT NULL,
    cutover_on DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO apv_accounting_cutover
SELECT 1, COALESCE((SELECT MAX(id) FROM accounts_payable_voucher),0),
    COALESCE((SELECT MAX(id) FROM direct_payment),0), NOW();
CREATE TABLE IF NOT EXISTS `liquidation_detail` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `liquidation_id` INT NOT NULL,
 `account_type_id` INT NULL,
 `invoice_no` VARCHAR(500) NULL,
 `expense_date` DATE NULL,
 `amount` DECIMAL(13,4) NULL,
 `description` TEXT NULL,
 `supplier_id` INT NULL,
 `tin` VARCHAR(500) NULL,
 `address` VARCHAR(500) NULL,
 `vat` DECIMAL(13,4) NULL,
 `total` DECIMAL(13,4) NULL,
 `is_vat` INT NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(liquidation_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `reimbursement_item` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `reimbursement_id` INT NOT NULL,
 `account_type_id` INT NULL,
 `supplier_id` INT NULL,
 `invoice_no` VARCHAR(500) NULL,
 `tin` VARCHAR(500) NULL,
 `address` VARCHAR(500) NULL,
 `description` TEXT NULL,
 `expense_date` DATE NULL,
 `amount` DECIMAL(13,4) NULL,
 `vat` DECIMAL(13,4) NULL,
 `total_value` DECIMAL(13,4) NULL,
 `total` DECIMAL(13,4) NULL,
 `remarks` TEXT NULL,
 `is_vat` INT NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(reimbursement_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `replenishment_item` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `replenishment_id` INT NOT NULL,
 `account_type_id` INT NULL,
 `supplier_id` INT NULL,
 `invoice_no` VARCHAR(500) NULL,
 `tin` VARCHAR(500) NULL,
 `address` VARCHAR(500) NULL,
 `description` TEXT NULL,
 `expense_date` DATE NULL,
 `amount` DECIMAL(13,4) NULL,
 `vat` DECIMAL(13,4) NULL,
 `total_value` DECIMAL(13,4) NULL,
 `total` DECIMAL(13,4) NULL,
 `remarks` TEXT NULL,
 `is_vat` INT NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(replenishment_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `deposit_detail` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `cash_advance_id` INT NOT NULL,
 `amount` DECIMAL(13,4) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(cash_advance_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `liquidation_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `liquidation_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(liquidation_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `reimbursement_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `reimbursement_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(reimbursement_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `replenishment_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `replenishment_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(replenishment_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `direct_payment_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `direct_payment_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(direct_payment_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `accounts_payable_voucher_attachment` (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `accounts_payable_voucher_id` INT NOT NULL,
 `file_name` VARCHAR(500) NULL,
 `file_path` VARCHAR(500) NULL,
 `file_url` VARCHAR(500) NULL,
 `mime` VARCHAR(500) NULL,
 added_by INT NOT NULL DEFAULT 0,added_on DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_by INT NULL,updated_on DATETIME NULL,is_deleted TINYINT DEFAULT 0,
 KEY idx_parent(accounts_payable_voucher_id,is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS idx_shared_center ON `petty_cash`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `cash_advance`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `liquidation`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `reimbursement`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `replenishment`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `change_fund`(cost_center_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_center ON `direct_payment`(cost_center_id,is_deleted,id);

-- Shared-master accounting only. No backfill. Install schema.sql first.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
DELIMITER $$
DROP PROCEDURE IF EXISTS gl_shared_clear$$
CREATE PROCEDURE gl_shared_clear(IN src VARCHAR(250), IN sid INT)
BEGIN
 UPDATE journal_entry_item i JOIN journal_entry j ON j.id=i.journal_entry_id
 SET i.is_deleted=1,i.updated_on=NOW()
 WHERE j.source=src AND j.source_id=sid AND COALESCE(j.is_migrated,0)=0 AND i.type='Automated' AND i.is_deleted=0;
 UPDATE journal_entry SET is_deleted=1,updated_on=NOW()
 WHERE source=src AND source_id=sid AND COALESCE(is_migrated,0)=0 AND is_deleted=0;
 UPDATE general_ledger SET is_deleted=1,updated_on=NOW()
 WHERE source=src AND source_id=sid AND COALESCE(is_migrated,0)=0 AND is_deleted=0;
END$$
DROP PROCEDURE IF EXISTS gl_shared_je$$
CREATE PROCEDURE gl_shared_je(IN src VARCHAR(250), IN sid INT)
BEGIN
 DECLARE jid INT;
 IF EXISTS (SELECT 1 FROM general_ledger WHERE source=src AND source_id=sid
  AND is_deleted=0 AND COALESCE(is_migrated,0)=0) THEN
  INSERT INTO journal_entry(cost_center_id,date,type,remarks,total_debit,total_credit,status,
   source,source_id,added_by,added_on,is_deleted,is_migrated)
  SELECT MIN(branch_id),MIN(date),src,gl_master_apv_context(src,sid,CONCAT(src,' #',sid)),SUM(debit),SUM(credit),'approved',src,sid,
   COALESCE(MIN(added_by),0),NOW(),0,0 FROM general_ledger
   WHERE source=src AND source_id=sid AND is_deleted=0 AND COALESCE(is_migrated,0)=0;
  SET jid=LAST_INSERT_ID();
  INSERT INTO journal_entry_item(journal_entry_id,account_type_id,debit,credit,remarks,type,added_by,added_on,is_deleted)
  SELECT jid,account_type_id,debit,credit,remark,'Automated',COALESCE(added_by,0),NOW(),0
  FROM general_ledger WHERE source=src AND source_id=sid AND is_deleted=0 AND COALESCE(is_migrated,0)=0;
 END IF;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_change_fund$$
CREATE PROCEDURE gl_shared_post_change_fund(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_cash_advance$$
CREATE PROCEDURE gl_shared_post_cash_advance(IN p_id INT)
BEGIN
    -- Compatibility no-op: cash-advance accounting is posted by the APV lifecycle.
    DO 0;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_petty_cash$$
CREATE PROCEDURE gl_shared_post_petty_cash(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_liquidation$$
CREATE PROCEDURE gl_shared_post_liquidation(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_reimbursement$$
CREATE PROCEDURE gl_shared_post_reimbursement(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_replenishment$$
CREATE PROCEDURE gl_shared_post_replenishment(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$
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

    IF NOT EXISTS (SELECT 1 FROM accounts_payable_voucher s JOIN branch b ON b.id=s.cost_center_id
        WHERE s.id=p_id AND (b.type='shared' OR s.type='refund') AND b.is_deleted=0) THEN LEAVE proc; END IF;
    IF IFNULL(@apv_entry_sync, 0) = 1 THEN LEAVE proc; END IF;
    IF gl_apv_is_legacy(p_id)=1 THEN
        IF NOT EXISTS (SELECT 1 FROM accounts_payable_voucher WHERE id=p_id
            AND is_deleted=0 AND status IN ('approved','open','closed')) THEN
            CALL gl_shared_clear('accounts_payable_voucher',p_id);
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
        LEFT JOIN account_type a ON a.id = e.account_type_id
        WHERE e.accounts_payable_voucher_id = p_id AND e.is_deleted = 0;
        IF v_count = 0 OR v_invalid > 0 OR v_debit <= 0 OR v_debit <> v_credit
            OR v_amount <= 0 OR v_payable <> v_amount THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid APV entries: balance or payable mismatch';
        END IF;
    END IF;

    CALL gl_shared_clear('accounts_payable_voucher', p_id);
    INSERT INTO general_ledger (branch_id, source, source_id, account_type_id, date,
        debit, credit, remark, added_by, added_on, is_deleted)
    SELECT CASE WHEN EXISTS (SELECT 1 FROM branch WHERE id=v.cost_center_id AND type='shared') THEN 0 ELSE v.cost_center_id END, 'accounts_payable_voucher', v.id, e.account_type_id,
        COALESCE(DATE(v.approved_on), DATE(v.added_on)), e.debit, e.credit,
        COALESCE(NULLIF(e.remarks,''), CONCAT('APV #',v.id,' entry #',e.id)),
        COALESCE(v.updated_by,v.added_by), NOW(), 0
    FROM accounts_payable_voucher v
    INNER JOIN accounts_payable_voucher_entry e ON e.accounts_payable_voucher_id = v.id
        AND e.is_deleted = 0
    WHERE v.id = p_id AND v.is_deleted = 0 AND v.status IN ('approved','open','closed');
    IF EXISTS (SELECT 1 FROM accounts_payable_voucher v WHERE v.id=p_id
        AND v.type='direct_payment' AND v.is_deleted=0 AND v.status IN ('approved','open','closed')) THEN
        CALL gl_shared_clear('direct_payment',(SELECT type_id FROM accounts_payable_voucher WHERE id=p_id));
    END IF;
    CALL gl_shared_je('accounts_payable_voucher',p_id);
    BEGIN
        DECLARE done INT DEFAULT 0;
        DECLARE did INT;
        DECLARE c CURSOR FOR SELECT id FROM accounts_payable_voucher_payment_detail WHERE accounts_payable_voucher_id=p_id;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
        OPEN c;
        refresh_payments: LOOP
            FETCH c INTO did;
            IF done=1 THEN LEAVE refresh_payments; END IF;
            CALL gl_shared_post_apvpd(did);
        END LOOP;
        CLOSE c;
    END;
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_apvpd$$
CREATE PROCEDURE gl_shared_post_apvpd(IN p_id INT)
proc: BEGIN
 IF NOT EXISTS (SELECT 1 FROM accounts_payable_voucher_payment_detail d
 JOIN accounts_payable_voucher v ON v.id=d.accounts_payable_voucher_id
 JOIN branch b ON b.id=v.cost_center_id WHERE d.id=p_id AND (b.type='shared' OR v.type='refund') AND b.is_deleted=0)
 THEN LEAVE proc; END IF;
 CALL gl_shared_clear('accounts_payable_voucher_payment_detail',p_id);
 INSERT INTO general_ledger(branch_id,source,source_id,account_type_id,date,debit,credit,remark,added_by,added_on,is_deleted)
 SELECT CASE WHEN b.type='shared' THEN 0 ELSE v.cost_center_id END,'accounts_payable_voucher_payment_detail',d.id,
 CASE n.side WHEN 0 THEN gl_account_id('201-111') ELSE COALESCE(p.account_type_id,gl_account_id('100-004')) END,
 COALESCE(d.date,DATE(d.added_on)),IF(n.side=0,d.amount,0),IF(n.side=1,d.amount,0),
 CONCAT('APV payment #',d.id),COALESCE(d.added_by,0),NOW(),0
 FROM accounts_payable_voucher_payment_detail d JOIN accounts_payable_voucher v ON v.id=d.accounts_payable_voucher_id
 JOIN branch b ON b.id=v.cost_center_id AND b.is_deleted=0
 JOIN accounts_payable_voucher_payment p ON p.id=d.accounts_payable_voucher_payment_id AND p.is_deleted=0
 CROSS JOIN (SELECT 0 AS side UNION ALL SELECT 1) n
 WHERE d.id=p_id AND d.is_deleted=0 AND d.amount>0 AND v.is_deleted=0 AND v.status IN ('approved','open','closed');
 CALL gl_shared_je('accounts_payable_voucher_payment_detail',p_id);
END$$
DROP PROCEDURE IF EXISTS gl_shared_post_payment$$
CREATE PROCEDURE gl_shared_post_payment(IN p_id INT)
BEGIN
 DECLARE done INT DEFAULT 0;
 DECLARE did INT;
 DECLARE c CURSOR FOR SELECT d.id FROM accounts_payable_voucher_payment_detail d
 JOIN accounts_payable_voucher v ON v.id=d.accounts_payable_voucher_id
 JOIN branch b ON b.id=v.cost_center_id AND (b.type='shared' OR v.type='refund') AND b.is_deleted=0
 WHERE d.accounts_payable_voucher_payment_id=p_id;
 DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
 OPEN c;
 payment_details: LOOP
  FETCH c INTO did;
  IF done=1 THEN LEAVE payment_details; END IF;
  CALL gl_shared_post_apvpd(did);
 END LOOP;
 CLOSE c;
END$$
DROP TRIGGER IF EXISTS trg_gl_shared_apvp_au$$
CREATE TRIGGER trg_gl_shared_apvp_au AFTER UPDATE ON accounts_payable_voucher_payment
FOR EACH ROW BEGIN CALL gl_shared_post_payment(NEW.id); END$$
DROP PROCEDURE IF EXISTS gl_shared_post_direct_payment$$
CREATE PROCEDURE gl_shared_post_direct_payment(IN p_id INT)
proc: BEGIN
 IF NOT EXISTS (SELECT 1 FROM direct_payment s JOIN branch b ON b.id=s.cost_center_id
 WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN LEAVE proc; END IF;
 IF EXISTS (SELECT 1 FROM apv_accounting_cutover WHERE id=1 AND p_id<=legacy_max_direct_payment_id)
 AND EXISTS (SELECT 1 FROM direct_payment WHERE id=p_id AND is_deleted=0 AND status='approved') THEN LEAVE proc; END IF;
 CALL gl_shared_clear('direct_payment',p_id);
END$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_ai$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_au$$
DROP TRIGGER IF EXISTS trg_gl_cash_advance_ai$$
DROP TRIGGER IF EXISTS trg_gl_cash_advance_au$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_ai$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_au$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_ai$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_au$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_ai$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_au$$
DROP TRIGGER IF EXISTS trg_gl_change_fund_ai$$
DROP TRIGGER IF EXISTS trg_gl_change_fund_ad$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_ad$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_ad$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_ad$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_ad$$

DROP TRIGGER IF EXISTS trg_gl_change_fund_au$$
DROP TRIGGER IF EXISTS trg_gl_direct_payment_ai$$
CREATE TRIGGER trg_gl_direct_payment_ai AFTER INSERT ON direct_payment
FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM branch WHERE id=NEW.cost_center_id AND type='shared' AND is_deleted=0) THEN CALL gl_shared_post_direct_payment(NEW.id); ELSE CALL gl_post_direct_payment(NEW.id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_direct_payment_au$$
CREATE TRIGGER trg_gl_direct_payment_au AFTER UPDATE ON direct_payment
FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM branch WHERE id=NEW.cost_center_id AND type='shared' AND is_deleted=0) THEN CALL gl_shared_post_direct_payment(NEW.id); ELSE CALL gl_post_direct_payment(NEW.id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_apv_ai$$
CREATE TRIGGER trg_gl_apv_ai AFTER INSERT ON accounts_payable_voucher
FOR EACH ROW BEGIN CALL gl_post_apv(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_apv_au$$
CREATE TRIGGER trg_gl_apv_au AFTER UPDATE ON accounts_payable_voucher
FOR EACH ROW BEGIN CALL gl_post_apv(NEW.id); END$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_detail_ai$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_detail_au$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_detail_ad$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_item_ai$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_item_au$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_item_ad$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_item_ai$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_item_au$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_item_ad$$
DROP TRIGGER IF EXISTS trg_gl_apv_entry_ai$$
CREATE TRIGGER trg_gl_apv_entry_ai AFTER INSERT ON accounts_payable_voucher_entry
FOR EACH ROW BEGIN CALL gl_post_apv(NEW.accounts_payable_voucher_id); END$$
DROP TRIGGER IF EXISTS trg_gl_apv_entry_au$$
CREATE TRIGGER trg_gl_apv_entry_au AFTER UPDATE ON accounts_payable_voucher_entry
FOR EACH ROW BEGIN CALL gl_post_apv(NEW.accounts_payable_voucher_id); IF OLD.accounts_payable_voucher_id<>NEW.accounts_payable_voucher_id THEN CALL gl_post_apv(OLD.accounts_payable_voucher_id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_apv_entry_ad$$
CREATE TRIGGER trg_gl_apv_entry_ad AFTER DELETE ON accounts_payable_voucher_entry
FOR EACH ROW BEGIN CALL gl_post_apv(OLD.accounts_payable_voucher_id); END$$
DROP TRIGGER IF EXISTS trg_gl_apvpd_ai$$
CREATE TRIGGER trg_gl_apvpd_ai AFTER INSERT ON accounts_payable_voucher_payment_detail
FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM accounts_payable_voucher v JOIN branch b ON b.id=v.cost_center_id WHERE v.id=NEW.accounts_payable_voucher_id AND (b.type='shared' OR v.type='refund') AND b.is_deleted=0) THEN CALL gl_shared_post_apvpd(NEW.id); ELSEIF EXISTS (SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_post_apv_payment_detail') THEN CALL gl_post_apv_payment_detail(NEW.id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_apvpd_au$$
CREATE TRIGGER trg_gl_apvpd_au AFTER UPDATE ON accounts_payable_voucher_payment_detail
FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM accounts_payable_voucher v JOIN branch b ON b.id=v.cost_center_id WHERE v.id=NEW.accounts_payable_voucher_id AND (b.type='shared' OR v.type='refund') AND b.is_deleted=0) THEN CALL gl_shared_post_apvpd(NEW.id); ELSEIF EXISTS (SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='gl_post_apv_payment_detail') THEN CALL gl_post_apv_payment_detail(NEW.id); END IF; END$$
DROP TRIGGER IF EXISTS trg_gl_apvpd_ad$$
CREATE TRIGGER trg_gl_apvpd_ad AFTER DELETE ON accounts_payable_voucher_payment_detail
FOR EACH ROW BEGIN CALL gl_shared_clear('accounts_payable_voucher_payment_detail',OLD.id); END$$
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

DROP PROCEDURE IF EXISTS gl_post_direct_payment$$
CREATE PROCEDURE gl_post_direct_payment(IN p_id INT)
BEGIN
 IF EXISTS (SELECT 1 FROM direct_payment s JOIN branch b ON b.id=s.cost_center_id
  WHERE s.id=p_id AND b.type='shared' AND b.is_deleted=0) THEN
  CALL gl_shared_post_direct_payment(p_id);
 ELSEIF NOT EXISTS (SELECT 1 FROM apv_accounting_cutover WHERE id=1 AND p_id<=legacy_max_direct_payment_id) THEN
  CALL gl_shared_clear('direct_payment',p_id);
 END IF;
END$$
DELIMITER ;

-- BEGIN MASTER REFUND APV ENUM SUPPORT
-- Run against each selected master/clinic APV schema. Preserve existing enum members/attributes.
DELIMITER $$
DROP PROCEDURE IF EXISTS extend_refund_apv_enum$$
CREATE PROCEDURE extend_refund_apv_enum(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_member VARCHAR(30))
proc: BEGIN
 DECLARE typ LONGTEXT;
 DECLARE nullable VARCHAR(3);
 DECLARE def LONGTEXT;
 DECLARE charset_name VARCHAR(64);
 DECLARE collation_name VARCHAR(64);
 DECLARE extra_value TEXT;
 DECLARE comment_value TEXT;
 SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA,COLUMN_COMMENT
 INTO typ,nullable,def,charset_name,collation_name,extra_value,comment_value
 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column;
 IF typ IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Required APV type/payee column missing'; END IF;
 IF LEFT(typ,5)<>'enum(' OR LOCATE(CONCAT(CHAR(39),p_member,CHAR(39)),typ)>0 THEN LEAVE proc; END IF;
 SET @refund_enum_ddl=CONCAT('ALTER TABLE `',p_table,'` MODIFY `',p_column,'` ',
  LEFT(typ,CHAR_LENGTH(typ)-1),',',QUOTE(p_member),') CHARACTER SET ',charset_name,' COLLATE ',collation_name,
  IF(nullable='YES',' NULL',' NOT NULL'),
  IF(def IS NULL,IF(nullable='YES',' DEFAULT NULL',''),CONCAT(' DEFAULT ',def)),
  ' ',extra_value,' COMMENT ',QUOTE(comment_value));
 PREPARE enum_stmt FROM @refund_enum_ddl; EXECUTE enum_stmt; DEALLOCATE PREPARE enum_stmt;
END$$
CALL extend_refund_apv_enum('accounts_payable_voucher','type','refund')$$
CALL extend_refund_apv_enum('accounts_payable_voucher','supplier_type','patient')$$
CALL extend_refund_apv_enum('accounts_payable_voucher_payment_detail','supplier_type','patient')$$
DROP PROCEDURE extend_refund_apv_enum$$
DELIMITER ;

-- BEGIN SHARED PROCUREMENT ACCOUNTING (generated by sql/shared_procurement/build.py)
-- Master procurement storage only. Additive definitions; no document backfill.

CREATE TABLE IF NOT EXISTS `purchase_request` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `branch_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL COMMENT 'branch_id',
  `capital_expenditure_id` int(11) DEFAULT NULL,
  `expenditure_type` enum('capex','opex') DEFAULT NULL,
  `type` enum('goods','services') NOT NULL DEFAULT 'goods',
  `needed_date` date NOT NULL,
  `request_date` date DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `delivery_branch_id` int(11) DEFAULT NULL,
  `vat_registered_tin` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `contact_ref` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `buyer_assigned` varchar(255) DEFAULT NULL,
  `buyer_contact` varchar(255) DEFAULT NULL,
  `shipping_delivery_notes` varchar(255) DEFAULT NULL,
  `expected_expiration_date_from` date DEFAULT NULL,
  `expected_expiration_date_to` date DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `barangay` varchar(255) DEFAULT NULL,
  `tax_type` varchar(255) DEFAULT NULL,
  `delivery_fee` decimal(13,4) DEFAULT 0.0000,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `general_discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `paid_amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_reason` text DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_request_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat_type` varchar(250) DEFAULT NULL,
  `total_value` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `price` decimal(13,4) DEFAULT 0.0000,
  `amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_request_attachment` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_url` longtext DEFAULT NULL,
  `mime` varchar(255) NOT NULL,
  `type` enum('pr','quotation') NOT NULL DEFAULT 'pr',
  `added_on` datetime NOT NULL DEFAULT current_timestamp(),
  `added_by` bigint(20) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `updated_by` bigint(20) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aoq` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `recommended_bidder_id` int(11) DEFAULT NULL COMMENT 'supplier',
  `recommended_by_id` int(11) DEFAULT NULL COMMENT 'user',
  `justification` varchar(250) DEFAULT NULL,
  `type` enum('service','goods') NOT NULL,
  `needed_date` date NOT NULL,
  `project_name` varchar(250) DEFAULT NULL,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `disapproved_reason` varchar(255) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aoq_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `aoq_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `name` varchar(250) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_request_id` int(11) NOT NULL,
  `po_number` varchar(250) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `type` enum('goods','services') NOT NULL DEFAULT 'goods',
  `request_date` date DEFAULT NULL,
  `delivery_branch_id` int(11) DEFAULT NULL,
  `expenditure_type` varchar(255) DEFAULT NULL,
  `capital_expenditure_id` int(11) DEFAULT NULL,
  `expected_expiration_date_from` date DEFAULT NULL,
  `expected_expiration_date_to` date DEFAULT NULL,
  `needed_date` date NOT NULL,
  `purchase_order_no` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `vat_registered_tin` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `revision` varchar(255) DEFAULT NULL,
  `contact_ref` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL,
  `incoterms` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `terms` varchar(250) DEFAULT NULL,
  `buyer_assigned` varchar(255) DEFAULT NULL,
  `buyer_contact` varchar(255) DEFAULT NULL,
  `shipping_delivery_notes` varchar(255) DEFAULT NULL,
  `tax_type` varchar(255) DEFAULT NULL,
  `delivery_fee` decimal(13,4) DEFAULT 0.0000,
  `vat_total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `general_discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `paid_amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `received_status` enum('pending','open','closed') NOT NULL DEFAULT 'pending',
  `is_sent` int(11) NOT NULL DEFAULT 0,
  `sent_by` int(11) DEFAULT NULL,
  `sent_on` datetime DEFAULT NULL,
  `is_closed_request` int(11) NOT NULL DEFAULT 0,
  `closed_request_status` enum('pending','requested','approved','disapproved') NOT NULL DEFAULT 'pending',
  `closed_request_reason` text DEFAULT NULL,
  `closed_request_by` int(11) DEFAULT NULL,
  `closed_request_on` datetime DEFAULT NULL,
  `closed_request_approved_by` int(11) DEFAULT NULL,
  `closed_request_approved_on` datetime DEFAULT NULL,
  `closed_request_disapproved_by` int(11) DEFAULT NULL,
  `closed_request_disapproved_on` datetime DEFAULT NULL,
  `closed_request_disapproved_reason` text DEFAULT NULL,
  `approved_by_department_director` int(11) DEFAULT NULL,
  `approved_on_department_director` datetime DEFAULT NULL,
  `approved_by_president` int(11) DEFAULT NULL,
  `approved_on_president` datetime DEFAULT NULL,
  `disapproved_reason` varchar(250) DEFAULT NULL,
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_order_id` int(11) NOT NULL,
  `purchase_request_id` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `expenditure_type` enum('capex','opex') DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat_type` varchar(250) DEFAULT NULL,
  `total_value` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `price` decimal(13,4) DEFAULT 0.0000,
  `amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_order_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `type` enum('goods','services') NOT NULL DEFAULT 'goods',
  `request_date` date DEFAULT NULL,
  `delivery_branch_id` int(11) DEFAULT NULL,
  `expenditure_type` varchar(255) DEFAULT NULL,
  `capital_expenditure_id` int(11) DEFAULT NULL,
  `expected_expiration_date_from` date DEFAULT NULL,
  `expected_expiration_date_to` date DEFAULT NULL,
  `needed_date` date NOT NULL,
  `receive_date` date DEFAULT NULL,
  `delivery_receipt_no` varchar(255) DEFAULT NULL,
  `sales_invoice_no` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `billing_address` varchar(255) DEFAULT NULL,
  `vat_registered_tin` varchar(255) DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `contact_ref` varchar(255) DEFAULT NULL,
  `buyer_assigned` varchar(255) DEFAULT NULL,
  `buyer_contact` varchar(255) DEFAULT NULL,
  `revision` varchar(255) DEFAULT NULL,
  `incoterms` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `tax_type` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL,
  `shipping_delivery_notes` varchar(255) DEFAULT NULL,
  `delivery_fee` decimal(13,4) DEFAULT 0.0000,
  `subtotal` decimal(13,4) DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `general_discount` decimal(13,4) DEFAULT 0.0000,
  `grand_total` decimal(13,4) DEFAULT 0.0000,
  `paid_amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `disapproved_reason` varchar(250) DEFAULT NULL,
  `approved_by` int(1) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(1) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `purchase_order_item_id` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `expenditure_type` enum('capex','opex') DEFAULT NULL,
  `item_id` int(11) NOT NULL,
  `ordered_qty` decimal(13,2) NOT NULL DEFAULT 0.00,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) DEFAULT NULL,
  `cost` decimal(13,4) DEFAULT 0.0000,
  `total` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `vat_type` varchar(250) DEFAULT NULL,
  `total_value` decimal(13,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(13,4) DEFAULT 0.0000,
  `price` decimal(13,4) DEFAULT 0.0000,
  `amount` decimal(13,4) DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_additional_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `unit` int(11) NOT NULL,
  `account_type_id` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_attachment` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `type` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_url` longtext DEFAULT NULL,
  `mime` varchar(255) NOT NULL,
  `added_on` datetime NOT NULL DEFAULT current_timestamp(),
  `added_by` bigint(20) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `updated_by` bigint(20) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receipt_note_item_expiry_date` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `date` date DEFAULT NULL,
  `qty` decimal(13,4) DEFAULT 0.0000,
  `added_by` int(11) NOT NULL,
  `added_on` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_return` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `receipt_note_id` int(11) NOT NULL,
  `purchase_order_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `return_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `grand_total` decimal(13,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','disapproved') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_on` datetime DEFAULT NULL,
  `disapproved_by` int(11) DEFAULT NULL,
  `disapproved_on` datetime DEFAULT NULL,
  `disapproved_reason` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_return_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `purchase_return_id` int(11) NOT NULL,
  `receipt_note_id` int(11) NOT NULL,
  `receipt_note_item_id` int(11) NOT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  `purchase_order_item_id` int(11) DEFAULT NULL,
  `account_type_id` int(11) NOT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `qty` decimal(13,2) NOT NULL DEFAULT 0.00,
  `unit` int(11) DEFAULT NULL,
  `price` decimal(13,2) DEFAULT NULL,
  `amount` decimal(13,2) DEFAULT NULL,
  `total` decimal(13,2) DEFAULT NULL,
  `vat` decimal(13,2) DEFAULT NULL,
  `vat_type` varchar(50) DEFAULT NULL,
  `total_value` decimal(13,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `purchase_request` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `purchase_request`(branch_id,is_deleted,id);

ALTER TABLE `aoq` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `aoq`(branch_id,is_deleted,id);

ALTER TABLE `purchase_order` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `purchase_order`(branch_id,is_deleted,id);

ALTER TABLE `receipt_note` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `receipt_note`(branch_id,is_deleted,id);

ALTER TABLE `purchase_return` ADD COLUMN IF NOT EXISTS branch_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_shared_owner ON `purchase_return`(branch_id,is_deleted,id);

ALTER TABLE receipt_note ADD COLUMN IF NOT EXISTS inventory_branch_id INT NULL;
CREATE TABLE IF NOT EXISTS shared_procurement_number(code VARCHAR(50) NOT NULL,year INT NOT NULL,last_number INT NOT NULL,PRIMARY KEY(code,year)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS shared_procurement_inventory(id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,document_type VARCHAR(40) NOT NULL,document_id INT NOT NULL,branch_id INT NOT NULL,inventory_branch_id INT NOT NULL,added_by INT NOT NULL,added_on DATETIME NOT NULL,UNIQUE KEY document_once(document_type,document_id)) ENGINE=InnoDB;

-- Apply to each of the seven purchasing schemas, using the matching environment.
-- Additive only: no source records, GL rows or journal entries are changed.
CREATE TABLE IF NOT EXISTS approval_reminder_sequence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module VARCHAR(32) NOT NULL,
    document_id INT NOT NULL,
    branch_id INT NOT NULL,
    stage VARCHAR(40) NOT NULL,
    creator_id INT NULL,
    triggered_by INT NULL,
    triggered_on DATETIME NOT NULL,
    next_eod_on DATETIME NOT NULL,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    cancelled_on DATETIME NULL,
    PRIMARY KEY (id),
    KEY document_stage (module,document_id,status),
    KEY eod_due (status,next_eod_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_reminder_delivery (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sequence_id BIGINT UNSIGNED NOT NULL,
    delivery_key VARCHAR(40) NOT NULL,
    due_on DATETIME NOT NULL,
    status ENUM('pending','processing','sent','logged','failed','uncertain','cancelled','skipped') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    claimed_on DATETIME NULL,
    completed_on DATETIME NULL,
    recipient_to TEXT NULL,
    recipient_cc TEXT NULL,
    error_message TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY sequence_delivery (sequence_id,delivery_key),
    KEY due_delivery (status,due_on),
    CONSTRAINT approval_reminder_delivery_sequence FOREIGN KEY (sequence_id)
        REFERENCES approval_reminder_sequence(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Ownership traversal indexes.
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_request_item`(purchase_request_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_request_attachment`(purchase_request_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `aoq_detail`(aoq_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_order_item`(purchase_order_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_item`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_additional_item`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_attachment`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `receipt_note_item_expiry_date`(receipt_note_id,is_deleted,id);
CREATE INDEX IF NOT EXISTS idx_shared_parent ON `purchase_return_item`(purchase_return_id,is_deleted,id);

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
