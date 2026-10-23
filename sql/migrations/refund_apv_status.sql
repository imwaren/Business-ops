-- Master refund approval columns for APV. Safe to re-run.
--   mysql -u root -p mytemr_master < sql/migrations/refund_apv_status.sql

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'refund'
      AND COLUMN_NAME = 'status'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE refund ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT ''pending''',
    'SELECT ''refund.status already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'refund'
      AND COLUMN_NAME = 'approved_by'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE refund ADD COLUMN approved_by INT(11) NULL',
    'SELECT ''refund.approved_by already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'refund'
      AND COLUMN_NAME = 'approved_on'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE refund ADD COLUMN approved_on DATETIME NULL',
    'SELECT ''refund.approved_on already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'refund'
      AND COLUMN_NAME = 'disapproved_by'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE refund ADD COLUMN disapproved_by INT(11) NULL',
    'SELECT ''refund.disapproved_by already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'refund'
      AND COLUMN_NAME = 'disapproved_on'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE refund ADD COLUMN disapproved_on DATETIME NULL',
    'SELECT ''refund.disapproved_on already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
