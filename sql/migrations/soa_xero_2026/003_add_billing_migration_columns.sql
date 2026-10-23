-- Idempotent ALTERs for Xero SOA populate on mytemr_master_uat.

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'invoice_no'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing ADD COLUMN invoice_no VARCHAR(64) NULL AFTER invoice_id',
    'SELECT ''billing.invoice_no already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing' AND INDEX_NAME = 'uk_billing_invoice_no'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing ADD UNIQUE KEY uk_billing_invoice_no (invoice_no)',
    'SELECT ''uk_billing_invoice_no already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'is_migrated'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing ADD COLUMN is_migrated TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''billing.is_migrated already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_entry' AND COLUMN_NAME = 'is_migrated'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_entry ADD COLUMN is_migrated TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''billing_entry.is_migrated already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_entry' AND COLUMN_NAME = 'remarks'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_entry ADD COLUMN remarks TEXT NULL',
    'SELECT ''billing_entry.remarks already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_payment' AND COLUMN_NAME = 'is_migrated'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_payment ADD COLUMN is_migrated TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''billing_payment.is_migrated already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'billing_payment_entry' AND COLUMN_NAME = 'is_migrated'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE billing_payment_entry ADD COLUMN is_migrated TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''billing_payment_entry.is_migrated already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
