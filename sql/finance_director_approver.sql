-- Add Finance Director approval stamps on costing and quotation.
-- Apply on mytemr_master. Safe to re-run: each column is added only if missing.
-- Do not drop existing quotation approved_by_finance_manager* columns.
--
-- Equivalent unguarded statements:
--   ALTER TABLE costing
--     ADD approved_by_finance_director INT NULL AFTER approved_on_sales_director,
--     ADD approved_on_finance_director DATETIME NULL AFTER approved_by_finance_director,
--     ADD approved_reason_finance_director TEXT NULL AFTER approved_reason_sales_director;
--   ALTER TABLE quotation
--     ADD approved_by_finance_director INT NULL AFTER approved_reason_finance_manager,
--     ADD approved_on_finance_director DATETIME NULL AFTER approved_by_finance_director,
--     ADD approved_reason_finance_director TEXT NULL AFTER approved_on_finance_director;

DELIMITER $$

DROP PROCEDURE IF EXISTS fd_add_column_if_missing$$
CREATE PROCEDURE fd_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = p_table
            AND COLUMN_NAME = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL fd_add_column_if_missing('costing', 'approved_by_finance_director', 'INT NULL AFTER approved_on_sales_director');
CALL fd_add_column_if_missing('costing', 'approved_on_finance_director', 'DATETIME NULL AFTER approved_by_finance_director');
CALL fd_add_column_if_missing('costing', 'approved_reason_finance_director', 'TEXT NULL AFTER approved_reason_sales_director');

CALL fd_add_column_if_missing('quotation', 'approved_by_finance_director', 'INT NULL AFTER approved_reason_finance_manager');
CALL fd_add_column_if_missing('quotation', 'approved_on_finance_director', 'DATETIME NULL AFTER approved_by_finance_director');
CALL fd_add_column_if_missing('quotation', 'approved_reason_finance_director', 'TEXT NULL AFTER approved_on_finance_director');

DROP PROCEDURE IF EXISTS fd_add_column_if_missing;
