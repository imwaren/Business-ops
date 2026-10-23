-- Final destructive step. Backups AND deployed dependency verification must precede this.
DELIMITER $$
DROP PROCEDURE IF EXISTS retire_legacy_payment_entries$$
CREATE PROCEDURE retire_legacy_payment_entries()
BEGIN
    IF IFNULL(@apv_legacy_backup_verified,0)<>1 OR IFNULL(@apv_deployment_verified,0)<>1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Verified recoverable backups and deployment are required before table removal';
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA=DATABASE() AND (EVENT_OBJECT_TABLE='direct_payment_entry'
            OR ACTION_STATEMENT LIKE '%direct_payment_entry%'))
        OR EXISTS (SELECT 1 FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME<>'retire_legacy_payment_entries'
            AND ROUTINE_DEFINITION LIKE '%direct_payment_entry%') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Installed SQL still depends on direct-payment entries';
    END IF;
    IF EXISTS (SELECT 1 FROM general_ledger WHERE source='direct_payment' AND is_deleted=0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Active direct-payment GL remains';
    END IF;
    DROP TABLE IF EXISTS direct_payment_entry;
END$$
DELIMITER ;
CALL retire_legacy_payment_entries();
DROP PROCEDURE retire_legacy_payment_entries;
