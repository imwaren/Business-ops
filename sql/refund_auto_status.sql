-- Auto-set refund.status to pending vs refunded from payment methods + amount.
-- Does not replace trg_gl_refund_ai / trg_gl_refund_au.
-- Apply on mytemr_master. Safe to re-run.
--
--   mysql -u root -p mytemr_master < sql/refund_auto_status.sql
--
-- Rules:
--   All cash, amount < 1000  -> refunded
--   All cash, amount >= 1000 -> pending
--   Cash and another method  -> pending
--   Fallback (no methods / all non-cash) -> refunded
--   approved / disapproved are never overwritten

DELIMITER $$

DROP FUNCTION IF EXISTS refund_compute_auto_status$$

CREATE FUNCTION refund_compute_auto_status(
    p_refund_id BIGINT,
    p_amount DECIMAL(13,2),
    p_current_status VARCHAR(255)
)
RETURNS VARCHAR(255)
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DECLARE v_cash INT DEFAULT 0;

    IF LOWER(IFNULL(p_current_status, '')) IN ('approved', 'disapproved') THEN
        RETURN p_current_status;
    END IF;

    IF p_refund_id IS NULL THEN
        RETURN 'refunded';
    END IF;

    SELECT
        COUNT(*),
        SUM(CASE WHEN LOWER(IFNULL(payment_method, '')) = 'cash' THEN 1 ELSE 0 END)
    INTO v_total, v_cash
    FROM refund_payment_method
    WHERE refund_id = p_refund_id
        AND IFNULL(is_deleted, 0) = 0;

    SET v_total = IFNULL(v_total, 0);
    SET v_cash = IFNULL(v_cash, 0);

    IF v_total > 0 AND v_cash = v_total THEN
        IF IFNULL(p_amount, 0) < 1000 THEN
            RETURN 'refunded';
        END IF;
        RETURN 'pending';
    END IF;

    IF v_cash > 0 AND v_cash < v_total THEN
        RETURN 'pending';
    END IF;

    RETURN 'refunded';
END$$

DROP PROCEDURE IF EXISTS refund_apply_auto_status$$

CREATE PROCEDURE refund_apply_auto_status(IN p_refund_id BIGINT)
BEGIN
    IF p_refund_id IS NOT NULL THEN
        UPDATE refund
        SET status = refund_compute_auto_status(id, amount, status)
        WHERE id = p_refund_id;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_refund_auto_status_bi$$

CREATE TRIGGER trg_refund_auto_status_bi
BEFORE INSERT ON refund
FOR EACH ROW
BEGIN
    SET NEW.status = refund_compute_auto_status(NULL, NEW.amount, NEW.status);
END$$

DROP TRIGGER IF EXISTS trg_refund_auto_status_bu$$

CREATE TRIGGER trg_refund_auto_status_bu
BEFORE UPDATE ON refund
FOR EACH ROW
BEGIN
    SET NEW.status = refund_compute_auto_status(NEW.id, NEW.amount, NEW.status);
END$$

DROP TRIGGER IF EXISTS trg_refund_pm_auto_status_ai$$

CREATE TRIGGER trg_refund_pm_auto_status_ai
AFTER INSERT ON refund_payment_method
FOR EACH ROW
BEGIN
    CALL refund_apply_auto_status(NEW.refund_id);
END$$

DROP TRIGGER IF EXISTS trg_refund_pm_auto_status_au$$

CREATE TRIGGER trg_refund_pm_auto_status_au
AFTER UPDATE ON refund_payment_method
FOR EACH ROW
BEGIN
    CALL refund_apply_auto_status(NEW.refund_id);
    IF OLD.refund_id <> NEW.refund_id THEN
        CALL refund_apply_auto_status(OLD.refund_id);
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_refund_pm_auto_status_ad$$

CREATE TRIGGER trg_refund_pm_auto_status_ad
AFTER DELETE ON refund_payment_method
FOR EACH ROW
BEGIN
    CALL refund_apply_auto_status(OLD.refund_id);
END$$

DELIMITER ;

-- Backfill rows that are not already approved or disapproved.
UPDATE refund
SET status = refund_compute_auto_status(id, amount, status)
WHERE is_deleted = 0
    AND LOWER(IFNULL(status, '')) NOT IN ('approved', 'disapproved');
