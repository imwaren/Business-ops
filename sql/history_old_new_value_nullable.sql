-- Allow NULL on history old_value / new_value so NULL-safe reason triggers can insert.
-- Apply on mytemr_master. Safe to re-run: each column is modified only if it is NOT NULL.
--
-- Equivalent unguarded statements:
--   ALTER TABLE request_history
--     MODIFY old_value mediumtext NULL,
--     MODIFY new_value mediumtext NULL;
--   ALTER TABLE costing_history
--     MODIFY old_value mediumtext NULL,
--     MODIFY new_value mediumtext NULL;
--   ALTER TABLE quotation_history
--     MODIFY old_value mediumtext NULL,
--     MODIFY new_value mediumtext NULL;
--   ALTER TABLE endorsement_history
--     MODIFY old_value mediumtext NULL,
--     MODIFY new_value mediumtext NULL;

DELIMITER $$

DROP PROCEDURE IF EXISTS history_allow_null_if_not_null$$
CREATE PROCEDURE history_allow_null_if_not_null(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64)
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = p_table
            AND COLUMN_NAME = p_column
            AND IS_NULLABLE = 'NO'
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` MODIFY `', p_column, '` mediumtext NULL');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL history_allow_null_if_not_null('request_history', 'old_value');
CALL history_allow_null_if_not_null('request_history', 'new_value');

CALL history_allow_null_if_not_null('costing_history', 'old_value');
CALL history_allow_null_if_not_null('costing_history', 'new_value');

CALL history_allow_null_if_not_null('quotation_history', 'old_value');
CALL history_allow_null_if_not_null('quotation_history', 'new_value');

CALL history_allow_null_if_not_null('endorsement_history', 'old_value');
CALL history_allow_null_if_not_null('endorsement_history', 'new_value');

DROP PROCEDURE IF EXISTS history_allow_null_if_not_null;
