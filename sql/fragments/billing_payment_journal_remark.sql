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
