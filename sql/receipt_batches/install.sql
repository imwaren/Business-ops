-- Run explicitly in each selected master/inventory schema before deploying PHP.
-- Local preparation only: do not run against UAT/production without a separate rollout.
-- Existing integer batch values become their text representation; no business-row DML.
-- DDL is not transactional. Back up table definitions and affected rows before live use.
DELIMITER $$
DROP PROCEDURE IF EXISTS install_receipt_batches$$
CREATE PROCEDURE install_receipt_batches()
BEGIN
    DECLARE pass_no INT DEFAULT 0;
    DECLARE position_no INT DEFAULT 0;
    DECLARE target_table VARCHAR(64);
    DECLARE column_definition TEXT;
    DECLARE column_type VARCHAR(64);
    DECLARE column_length BIGINT;
    DECLARE column_nullable VARCHAR(3);
    DECLARE column_collation VARCHAR(64);
    DECLARE column_default_value TEXT;
    DECLARE column_extra VARCHAR(255);
    DECLARE column_comment_value TEXT;
    DECLARE index_name_value VARCHAR(64);
    DECLARE index_columns TEXT;
    DECLARE found_count INT;
    DECLARE failure_message VARCHAR(128);
    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Select the intended schema before installation.';
    END IF;
    -- Validate every existing batch column before applying any column changes.
    WHILE pass_no < 2 DO
        SET position_no = 0;
        WHILE position_no < 3 DO
            SET target_table = CASE position_no
                WHEN 0 THEN 'receipt_note_item'
                WHEN 1 THEN 'receipt_note_item_expiry_date'
                ELSE 'branch_item_transfer_item' END;
            SELECT COUNT(*) INTO found_count FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = target_table AND TABLE_TYPE = 'BASE TABLE';
            IF found_count = 1 THEN
                SELECT COUNT(*) INTO found_count FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = target_table AND COLUMN_NAME = 'batch_no';
                SET column_type = NULL;
                SET column_length = 255;
                SET column_comment_value = '';
                SET column_collation = NULL;
                SET column_default_value = NULL;
                IF found_count = 1 THEN
                    SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLLATION_NAME,
                        COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
                        INTO column_type, column_length, column_nullable, column_collation,
                            column_default_value, column_extra, column_comment_value
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = target_table AND COLUMN_NAME = 'batch_no';
                    IF column_nullable <> 'YES'
                        OR NOT ((column_type = 'varchar' AND column_length >= 255)
                            OR (position_no > 0 AND column_type = 'int'))
                        OR column_extra <> '' THEN
                        SET failure_message = CONCAT('Incompatible batch_no column in ', target_table, '; review its type or nullability.');
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = failure_message;
                    END IF;
                END IF;
                IF pass_no = 1 THEN
                    SET column_length = IF(column_type = 'varchar', column_length, 255);
                    SET column_definition = CONCAT('VARCHAR(', column_length,
                        ') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT ',
                        CASE
                            WHEN column_default_value IS NULL
                                OR (LOCATE('MariaDB', VERSION()) > 0 AND column_default_value = 'NULL') THEN 'NULL'
                            WHEN column_type = 'varchar' AND LOCATE('MariaDB', VERSION()) = 0 THEN QUOTE(column_default_value)
                            ELSE column_default_value
                        END, ' COMMENT ', QUOTE(column_comment_value));
                    IF found_count = 0 THEN
                        SET column_definition = CONCAT(column_definition, CASE position_no
                            WHEN 0 THEN ' AFTER `expenditure_type`'
                            WHEN 1 THEN ' AFTER `qty`'
                            ELSE ' AFTER `item_id`' END);
                        SET @receipt_batch_sql = CONCAT('ALTER TABLE `', target_table, '` ADD COLUMN `batch_no` ', column_definition);
                        PREPARE receipt_batch_statement FROM @receipt_batch_sql;
                        EXECUTE receipt_batch_statement;
                        DEALLOCATE PREPARE receipt_batch_statement;
                    ELSEIF column_type <> 'varchar' OR column_collation <> 'utf8mb4_bin' THEN
                        SET @receipt_batch_sql = CONCAT('ALTER TABLE `', target_table, '` MODIFY COLUMN `batch_no` ', column_definition);
                        PREPARE receipt_batch_statement FROM @receipt_batch_sql;
                        EXECUTE receipt_batch_statement;
                        DEALLOCATE PREPARE receipt_batch_statement;
                    END IF;
                    IF position_no > 0 THEN
                        SET index_name_value = IF(position_no = 1, 'idx_expiry_batch', 'idx_transfer_item_batch');
                        SET index_columns = IF(position_no = 1, '`batch_no`,`receipt_note_item_id`,`is_deleted`',
                            '`item_id`,`batch_no`,`branch_item_transfer_id`,`is_deleted`');
                        SELECT COUNT(*) INTO found_count FROM information_schema.STATISTICS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = target_table AND INDEX_NAME = index_name_value;
                        IF found_count = 0 THEN
                            SET @receipt_batch_sql = CONCAT('ALTER TABLE `', target_table, '` ADD INDEX `', index_name_value, '` (', index_columns, ')');
                            PREPARE receipt_batch_statement FROM @receipt_batch_sql;
                            EXECUTE receipt_batch_statement;
                            DEALLOCATE PREPARE receipt_batch_statement;
                        END IF;
                    END IF;
                END IF;
            END IF;
            SET position_no = position_no + 1;
        END WHILE;
        SET pass_no = pass_no + 1;
    END WHILE;
END$$
CALL install_receipt_batches()$$
DROP PROCEDURE install_receipt_batches$$
DELIMITER ;
