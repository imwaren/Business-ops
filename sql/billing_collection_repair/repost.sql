-- Run ONLY in the intended master database, after install.sql. Explicit target: 1447.
-- No source records are changed. Migrated/manual journals remain untouched.
SELECT id, mop, account_type_id, grand_total, wht_amount, wht_account, is_deleted
FROM billing_payment WHERE id IN (1447);
SELECT source_id, is_deleted, COUNT(*) AS rows_count, SUM(debit) AS debit, SUM(credit) AS credit
FROM general_ledger WHERE source = 'billing_payment' AND source_id IN (1447)
GROUP BY source_id, is_deleted;

DELIMITER $$
DROP PROCEDURE IF EXISTS gl_repost_collection_checked$$
CREATE PROCEDURE gl_repost_collection_checked(IN p_id INT)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_debit DECIMAL(20,4);
    DECLARE v_credit DECIMAL(20,4);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    START TRANSACTION;
    IF NOT EXISTS (SELECT 1 FROM billing_payment WHERE id = p_id AND is_deleted = 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Selected collection is missing or deleted';
    END IF;
    CALL gl_post_billing_payment(p_id);
    SELECT COUNT(*), IFNULL(SUM(debit), 0), IFNULL(SUM(credit), 0)
    INTO v_count, v_debit, v_credit
    FROM general_ledger WHERE source = 'billing_payment' AND source_id = p_id
        AND is_deleted = 0 AND IFNULL(is_migrated, 0) = 0;
    IF v_count = 0 OR v_debit <> v_credit THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Collection repost produced empty or unbalanced GL';
    END IF;
    IF (SELECT COUNT(*) FROM journal_entry WHERE source = 'billing_payment' AND source_id = p_id
        AND is_deleted = 0 AND IFNULL(is_migrated, 0) = 0) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Collection repost must produce one automated JE';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM journal_entry WHERE source = 'billing_payment' AND source_id = p_id
        AND is_deleted = 0 AND IFNULL(is_migrated, 0) = 0
        AND total_debit = v_debit AND total_credit = v_credit) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Collection JE header does not match GL';
    END IF;
    IF (SELECT COUNT(*) FROM journal_entry_item i INNER JOIN journal_entry j ON j.id = i.journal_entry_id
        WHERE j.source = 'billing_payment' AND j.source_id = p_id AND j.is_deleted = 0
            AND IFNULL(j.is_migrated, 0) = 0 AND i.is_deleted = 0) <> v_count THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Collection JE item count does not match GL';
    END IF;
    IF (SELECT IFNULL(SUM(i.debit), 0) FROM journal_entry_item i
        INNER JOIN journal_entry j ON j.id = i.journal_entry_id
        WHERE j.source = 'billing_payment' AND j.source_id = p_id AND j.is_deleted = 0
            AND IFNULL(j.is_migrated, 0) = 0 AND i.is_deleted = 0) <> v_debit
        OR (SELECT IFNULL(SUM(i.credit), 0) FROM journal_entry_item i
        INNER JOIN journal_entry j ON j.id = i.journal_entry_id
        WHERE j.source = 'billing_payment' AND j.source_id = p_id AND j.is_deleted = 0
            AND IFNULL(j.is_migrated, 0) = 0 AND i.is_deleted = 0) <> v_credit THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Collection JE item totals do not match GL';
    END IF;
    COMMIT;
END$$
DELIMITER ;

CALL gl_repost_collection_checked(1447);
DROP PROCEDURE gl_repost_collection_checked;

SELECT branch_id, account_type_id, SUM(debit) AS debit, SUM(credit) AS credit
FROM general_ledger WHERE source = 'billing_payment' AND source_id = 1447
    AND is_deleted = 0 AND IFNULL(is_migrated, 0) = 0
GROUP BY branch_id, account_type_id ORDER BY branch_id, account_type_id;
SELECT id, source, source_id, status, total_debit, total_credit
FROM journal_entry WHERE source = 'billing_payment' AND source_id = 1447
    AND is_deleted = 0 AND IFNULL(is_migrated, 0) = 0;
