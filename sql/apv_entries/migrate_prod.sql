-- Execute only after the read-only gate reports ZERO exceptions and backups exist.
-- Session guard suppresses old/new child hooks until the complete sets are saved.
DELIMITER $$
DROP PROCEDURE IF EXISTS migrate_apv_entries$$
CREATE PROCEDURE migrate_apv_entries()
BEGIN
    DECLARE v_bad INT DEFAULT 0;
    DECLARE v_prior INT DEFAULT NULL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET @apv_entry_sync = v_prior;
        DROP TEMPORARY TABLE IF EXISTS apv_migration_candidate;
        RESIGNAL;
    END;
    SET v_prior = @apv_entry_sync;
    START TRANSACTION;
    SET @apv_entry_sync = 1;
    DROP TEMPORARY TABLE IF EXISTS apv_migration_candidate;
    CREATE TEMPORARY TABLE apv_migration_candidate (
        voucher_id INT, account_type_id INT, debit DECIMAL(13,4), credit DECIMAL(13,4),
        remarks VARCHAR(250), added_by INT, added_on DATETIME
    ) ENGINE=InnoDB;
    INSERT INTO apv_migration_candidate
    SELECT v.id,e.account_type_id,e.debit,e.credit,e.remarks,e.added_by,e.added_on
    FROM accounts_payable_voucher v JOIN accounts_payable_voucher_entry e
        ON e.accounts_payable_voucher_id=v.id AND e.is_deleted=0
    WHERE v.is_deleted=0;
    INSERT INTO apv_migration_candidate
    SELECT v.id,g.account_type_id,g.debit,g.credit,LEFT(g.remark,250),
        COALESCE(g.added_by,v.added_by),COALESCE(g.added_on,v.added_on)
    FROM accounts_payable_voucher v JOIN general_ledger g
        ON g.source='accounts_payable_voucher' AND g.source_id=v.id AND g.is_deleted=0
    WHERE v.is_deleted=0 AND NOT EXISTS (
        SELECT 1 FROM accounts_payable_voucher_entry e
        WHERE e.accounts_payable_voucher_id=v.id AND e.is_deleted=0);
    SELECT COUNT(*) INTO v_bad FROM apv_migration_candidate e
    LEFT JOIN mytemr_master.account_type a ON a.id=e.account_type_id
    WHERE a.id IS NULL OR a.is_deleted<>0 OR a.status<>'active'
        OR e.debit IS NULL OR e.credit IS NULL OR e.debit<0 OR e.credit<0
        OR ((e.debit>0)=(e.credit>0));
    IF v_bad>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical APV entries have invalid accounts or sides'; END IF;
    SELECT COUNT(*) INTO v_bad FROM accounts_payable_voucher v
    LEFT JOIN (
        SELECT e.voucher_id,COUNT(*) AS entry_count,SUM(e.debit) AS dr,SUM(e.credit) AS cr,
            SUM(CASE WHEN a.code='201-111' THEN e.credit-e.debit ELSE 0 END) AS payable
        FROM apv_migration_candidate e JOIN mytemr_master.account_type a ON a.id=e.account_type_id
        GROUP BY e.voucher_id
    ) sums ON sums.voucher_id=v.id
    WHERE v.is_deleted=0 AND (IFNULL(sums.entry_count,0)=0 OR sums.dr<>sums.cr
        OR sums.dr<=0 OR sums.payable<>v.amount OR v.amount<=0);
    IF v_bad>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical APV mapping missing, unbalanced, or payable mismatch'; END IF;
    SELECT COUNT(*) INTO v_bad FROM direct_payment d
    WHERE d.is_deleted=0 AND d.duration_from IS NOT NULL AND d.duration_to IS NOT NULL AND (
        (SELECT COUNT(*) FROM accounts_payable_voucher v WHERE v.type='direct_payment'
            AND v.type_id=d.id AND v.is_deleted=0)<>1
        OR IFNULL((SELECT SUM(e.amount) FROM accounts_payable_voucher_amortization_entry e
            JOIN accounts_payable_voucher v ON v.id=e.accounts_payable_voucher_id
            WHERE v.type='direct_payment' AND v.type_id=d.id AND v.is_deleted=0 AND e.is_deleted=0),0)<>d.amount);
    IF v_bad>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Explicit prepaid allocations are missing or amount mismatched'; END IF;
    SELECT COUNT(*) INTO v_bad FROM accounts_payable_voucher_amortization_entry e
    LEFT JOIN mytemr_master.account_type expense ON expense.id=e.expense_account_type_id
    LEFT JOIN mytemr_master.account_type prepaid ON prepaid.id=e.prepaid_account_type_id
    WHERE e.is_deleted=0 AND (e.amount<=0 OR expense.id IS NULL OR prepaid.id IS NULL
        OR expense.status<>'active' OR prepaid.status<>'active' OR expense.is_deleted<>0
        OR prepaid.is_deleted<>0 OR expense.id=prepaid.id);
    IF v_bad>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid prepaid allocation accounts'; END IF;
    SELECT COUNT(*) INTO v_bad FROM general_ledger g
    WHERE g.source='direct_payment' AND g.is_deleted=0 AND
        (SELECT COUNT(*) FROM accounts_payable_voucher v WHERE v.type='direct_payment'
            AND v.type_id=g.source_id AND v.is_deleted=0)<>1;
    IF v_bad>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Direct-payment posting has no unique APV successor'; END IF;
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='direct_payment_entry') THEN
        SELECT COUNT(*) INTO v_bad FROM (
            SELECT e.direct_payment_id,SUM(e.debit) AS dr,SUM(e.credit) AS cr,
                SUM(CASE WHEN e.debit IS NULL OR e.credit IS NULL OR e.debit<0 OR e.credit<0
                    OR ((e.debit>0)=(e.credit>0)) THEN 1 ELSE 0 END) AS invalid_sides
            FROM direct_payment_entry e WHERE e.is_deleted=0 GROUP BY e.direct_payment_id
        ) sums LEFT JOIN direct_payment d ON d.id=sums.direct_payment_id
        WHERE d.id IS NULL OR sums.invalid_sides>0 OR sums.dr<>sums.cr OR sums.dr<>d.amount;
        IF v_bad>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unresolved historical direct-payment entries'; END IF;
    END IF;
    INSERT INTO accounts_payable_voucher_entry (accounts_payable_voucher_id,account_type_id,
        debit,credit,remarks,added_by,added_on,is_deleted)
    SELECT e.voucher_id,e.account_type_id,e.debit,e.credit,e.remarks,e.added_by,e.added_on,0
    FROM apv_migration_candidate e WHERE NOT EXISTS (
        SELECT 1 FROM accounts_payable_voucher_entry existing
        WHERE existing.accounts_payable_voucher_id=e.voucher_id AND existing.is_deleted=0);
    SET @apv_entry_sync = v_prior;
    COMMIT;
    DROP TEMPORARY TABLE apv_migration_candidate;
END$$
DELIMITER ;
CALL migrate_apv_entries();
DROP PROCEDURE migrate_apv_entries;
