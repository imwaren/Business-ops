-- Add invoice date to billing in both master databases. Safe to rerun.

USE `mytemr_master_uat`;

SET @has_invoice_date = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'invoice_date'
);
SET @sql = IF(@has_invoice_date = 0,
    'ALTER TABLE billing ADD COLUMN invoice_date DATE NULL AFTER invoice_no',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

USE `mytemr_master`;

SET @has_invoice_date = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'invoice_date'
);
SET @sql = IF(@has_invoice_date = 0,
    'ALTER TABLE billing ADD COLUMN invoice_date DATE NULL AFTER invoice_no',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
