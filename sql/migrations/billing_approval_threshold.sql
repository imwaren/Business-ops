-- Adds billing approval tracking to both master databases. Safe to rerun.

USE `mytemr_master_uat`;

SET @has_approved_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'approved_by'
);
SET @sql = IF(@has_approved_by = 0,
    'ALTER TABLE billing ADD COLUMN approved_by INT NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_approved_on = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'approved_on'
);
SET @sql = IF(@has_approved_on = 0,
    'ALTER TABLE billing ADD COLUMN approved_on DATETIME NULL AFTER approved_by',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE billing
    MODIFY COLUMN status ENUM('pending','approved','open_bill','closed_bill','cancelled') NOT NULL DEFAULT 'pending';

USE `mytemr_master`;

SET @has_approved_by = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'approved_by'
);
SET @sql = IF(@has_approved_by = 0,
    'ALTER TABLE billing ADD COLUMN approved_by INT NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_approved_on = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'billing' AND COLUMN_NAME = 'approved_on'
);
SET @sql = IF(@has_approved_on = 0,
    'ALTER TABLE billing ADD COLUMN approved_on DATETIME NULL AFTER approved_by',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE billing
    MODIFY COLUMN status ENUM('pending','approved','open_bill','closed_bill','cancelled') NOT NULL DEFAULT 'pending';
