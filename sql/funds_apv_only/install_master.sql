-- Fund accounting through APVs only. Definitions only; no history cleanup/repost.
DELIMITER $$
DROP TRIGGER IF EXISTS trg_gl_change_fund_ai$$
DROP TRIGGER IF EXISTS trg_gl_change_fund_au$$
DROP TRIGGER IF EXISTS trg_gl_change_fund_ad$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_ai$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_au$$
DROP TRIGGER IF EXISTS trg_gl_petty_cash_ad$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_ai$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_au$$
DROP TRIGGER IF EXISTS trg_gl_liquidation_ad$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_ai$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_au$$
DROP TRIGGER IF EXISTS trg_gl_reimbursement_ad$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_ai$$
DROP TRIGGER IF EXISTS trg_gl_replenishment_au$$
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

DROP PROCEDURE IF EXISTS gl_shared_post_change_fund$$
CREATE PROCEDURE gl_shared_post_change_fund(IN p_id INT)
BEGIN
    -- Compatibility no-op: fund accounting posts through the APV lifecycle only.
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
DELIMITER ;
