-- Cash advances post through APVs only. No data updates or historical cleanup.
DELIMITER $$
DROP TRIGGER IF EXISTS trg_gl_cash_advance_ai$$
DROP TRIGGER IF EXISTS trg_gl_cash_advance_au$$

DROP PROCEDURE IF EXISTS gl_shared_post_cash_advance$$
CREATE PROCEDURE gl_shared_post_cash_advance(IN p_id INT)
BEGIN
    -- Compatibility no-op: cash-advance accounting is posted by the APV lifecycle.
    DO 0;
END$$

DROP PROCEDURE IF EXISTS gl_shared_post_liquidation$$
CREATE PROCEDURE gl_shared_post_liquidation(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
    DO 0;
END$$
DELIMITER ;
