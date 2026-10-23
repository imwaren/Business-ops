-- Install the new scoped posting routines before this transaction.
DELIMITER $$
DROP PROCEDURE IF EXISTS rebuild_apv_entries$$
CREATE PROCEDURE rebuild_apv_entries()
BEGIN
    DECLARE v_id INT;
    DECLARE done INT DEFAULT 0;
    DECLARE vouchers CURSOR FOR SELECT id FROM accounts_payable_voucher ORDER BY id;
    DECLARE payments CURSOR FOR SELECT DISTINCT source_id FROM general_ledger WHERE source='direct_payment' AND is_deleted=0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
    START TRANSACTION;
    OPEN vouchers;
    voucher_loop: LOOP
        FETCH vouchers INTO v_id;
        IF done=1 THEN LEAVE voucher_loop; END IF;
        CALL gl_post_apv(v_id);
    END LOOP;
    CLOSE vouchers;
    SET done=0;
    OPEN payments;
    payment_loop: LOOP
        FETCH payments INTO v_id;
        IF done=1 THEN LEAVE payment_loop; END IF;
        CALL gl_post_direct_payment(v_id);
    END LOOP;
    CLOSE payments;
    COMMIT;
END$$
DELIMITER ;
CALL rebuild_apv_entries();
DROP PROCEDURE rebuild_apv_entries;
