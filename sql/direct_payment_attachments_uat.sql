-- Direct-payment prepaid amortization columns and attachment metadata (UAT).
-- Idempotent across the five clinic databases.
USE `mytemr_diagnostic_uat`;
DELIMITER $$

DROP PROCEDURE IF EXISTS migrate_direct_payment_attachments$$
CREATE PROCEDURE migrate_direct_payment_attachments()
BEGIN
    DECLARE finished INT DEFAULT 0;
    DECLARE current_schema VARCHAR(64);
    DECLARE column_exists INT DEFAULT 0;

    DECLARE schema_cursor CURSOR FOR
        SELECT schema_name
        FROM information_schema.schemata
        WHERE schema_name IN (
            'mytemr_diagnostic_mobile_uat',
            'mytemr_diagnostic_uat',
            'mytemr_diagnostic_mactan_uat',
            'mytemr_diagnostic_conso_uat',
            'mytemr_diagnostic_mantlewood_uat'
        )
        ORDER BY schema_name;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = 1;

    OPEN schema_cursor;
    schema_loop: LOOP
        FETCH schema_cursor INTO current_schema;
        IF finished = 1 THEN
            LEAVE schema_loop;
        END IF;

        SELECT COUNT(*) INTO column_exists
        FROM information_schema.columns
        WHERE table_schema = current_schema AND table_name = 'direct_payment'
          AND column_name = 'credit_account_type_id';
        IF column_exists = 0 THEN
            SET @direct_payment_ddl = CONCAT(
                'ALTER TABLE `', current_schema,
                '`.`direct_payment` ADD COLUMN `credit_account_type_id` INT(11) NULL DEFAULT NULL AFTER `account_type_id`'
            );
            PREPARE direct_payment_statement FROM @direct_payment_ddl;
            EXECUTE direct_payment_statement;
            DEALLOCATE PREPARE direct_payment_statement;
        END IF;

        SELECT COUNT(*) INTO column_exists
        FROM information_schema.columns
        WHERE table_schema = current_schema AND table_name = 'direct_payment'
          AND column_name = 'duration_from';
        IF column_exists = 0 THEN
            SET @direct_payment_ddl = CONCAT(
                'ALTER TABLE `', current_schema,
                '`.`direct_payment` ADD COLUMN `duration_from` DATE NULL DEFAULT NULL AFTER `due_date`'
            );
            PREPARE direct_payment_statement FROM @direct_payment_ddl;
            EXECUTE direct_payment_statement;
            DEALLOCATE PREPARE direct_payment_statement;
        END IF;

        SELECT COUNT(*) INTO column_exists
        FROM information_schema.columns
        WHERE table_schema = current_schema AND table_name = 'direct_payment'
          AND column_name = 'duration_to';
        IF column_exists = 0 THEN
            SET @direct_payment_ddl = CONCAT(
                'ALTER TABLE `', current_schema,
                '`.`direct_payment` ADD COLUMN `duration_to` DATE NULL DEFAULT NULL AFTER `duration_from`'
            );
            PREPARE direct_payment_statement FROM @direct_payment_ddl;
            EXECUTE direct_payment_statement;
            DEALLOCATE PREPARE direct_payment_statement;
        END IF;
    END LOOP;
    CLOSE schema_cursor;
END$$

CALL migrate_direct_payment_attachments()$$
DROP PROCEDURE migrate_direct_payment_attachments$$

DELIMITER ;

CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_mobile_uat`.`direct_payment_attachment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `direct_payment_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_url` varchar(500) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `added_by` int(11) NOT NULL,
  `added_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_on` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_direct_payment_attachment_parent` (`direct_payment_id`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_uat`.`direct_payment_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`direct_payment_attachment`;
CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_mactan_uat`.`direct_payment_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`direct_payment_attachment`;
CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_conso_uat`.`direct_payment_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`direct_payment_attachment`;
CREATE TABLE IF NOT EXISTS `mytemr_diagnostic_mantlewood_uat`.`direct_payment_attachment` LIKE `mytemr_diagnostic_mobile_uat`.`direct_payment_attachment`;
