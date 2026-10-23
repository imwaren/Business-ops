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
