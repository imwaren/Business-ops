-- Add is_migrated on general_ledger and journal_entry (master + each clinic DB).
-- Also add journal_entry.type if missing. Safe to re-run.

SET @db := DATABASE();

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'general_ledger'
      AND COLUMN_NAME = 'is_migrated'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE general_ledger ADD COLUMN is_migrated TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''general_ledger.is_migrated already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'journal_entry'
      AND COLUMN_NAME = 'is_migrated'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE journal_entry ADD COLUMN is_migrated TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''journal_entry.is_migrated already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'journal_entry'
      AND COLUMN_NAME = 'type'
);
SET @sql := IF(
    @exists = 0,
    'ALTER TABLE journal_entry ADD COLUMN type VARCHAR(64) NULL',
    'SELECT ''journal_entry.type already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
